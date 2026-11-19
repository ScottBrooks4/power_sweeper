<?php

declare(strict_types=1);

namespace PowerSweeper\WebApp;

/**
 * Pixel canvas HTML: resolved geometry, flex AutoLayout, theme fills, class CSS after sweeper.
 *
 * Geometry is emitted as HTML + generated.css. Runtime boots from app-logic.js
 * and native handlers/*.js — not the conversion archive.
 */
final class WebAppPixelRenderer
{
    /** @var array<string, list<array<string, string>>> */
    private array $collections = [];

    /** @var array<string, string> name => public url */
    private array $media = [];

    /** Uniquify radio group name= attributes across component clones. */
    private int $radioSeq = 0;

    /** @var array<string, string> named SVG markup from app Formulas (IconAdminSidebar, etc.) */
    private array $formulaIcons = [];

    /** Raw app Formulas blob for named HTML builders (fnCreateSummaryHTML, etc.). */
    private string $formulasBlob = '';

    /** @var array<string, true> section-top HTML anchors already emitted this render */
    private array $emittedSectionAnchors = [];

    /** When true, omit Power Apps X/Y/Width/Height so the page flows with the device. */
    private bool $flow = false;

    public function __construct(
        private readonly WebAppLayoutResolver $layout = new WebAppLayoutResolver(),
        private readonly WebAppTheme $theme = new WebAppTheme(),
    ) {
    }

    /**
     * @param array<string, mixed> $ir
     */
    public function renderIntermediate(array $ir, bool $flow = false): string
    {
        $this->flow = $flow;
        $this->collections = is_array($ir['collections'] ?? null) ? $ir['collections'] : [];
        $this->media = [];
        $this->radioSeq = 0;
        $this->emittedSectionAnchors = [];
        $this->formulasBlob = $this->findFormulasBlob($ir);
        $this->formulaIcons = $this->extractFormulaIcons($ir);
        $this->hydrateNavCollectionsFromFormulas();
        foreach (is_array($ir['media'] ?? null) ? $ir['media'] : [] as $res) {
            if (!is_array($res) || ($res['name'] ?? '') === '') {
                continue;
            }
            $file = (string) ($res['file'] ?? '');
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION) ?: 'png');
            $safe = preg_replace('/[^\w.-]+/', '_', (string) $res['name']) ?: 'media';
            $this->media[(string) $res['name']] = 'assets/media/' . $safe . '.' . $ext;
        }

        $ir = $this->expandComponents($ir);
        $ir = $this->layout->resolve($ir);
        $title = htmlspecialchars((string) (($ir['document']['name'] ?? null) ?: 'Power Sweeper Web App'), ENT_QUOTES);
        $width = (int) (($ir['document']['layout']['width'] ?? null) ?: 1366);
        $height = (int) (($ir['document']['layout']['height'] ?? null) ?: 768);
        $screens = is_array($ir['screens'] ?? null) ? $ir['screens'] : [];
        $start = $this->startScreenName($ir, $screens);

        $screenMarkup = '';
        foreach ($screens as $screen) {
            if (!is_array($screen)) {
                continue;
            }
            $name = (string) ($screen['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $active = $name === $start;
            $fillFx = (string) (($screen['properties']['Fill'] ?? null) ?: 'gblTheme.Page');
            $fill = $this->theme->color($fillFx);
            if ($fill === 'transparent') {
                $fill = $this->theme->color('gblTheme.Page');
            }
            $activeClass = $active ? ' screen--active' : '';
            if ($this->flow) {
                $screenStyle = 'position:relative;width:100%;height:auto;background:' . $fill . ';overflow:visible;';
                $kidsAbsolute = false;
            } else {
                $screenStyle = 'position:relative;width:' . $width . 'px;height:' . $height
                    . 'px;background:' . $fill . ';overflow:hidden;';
                $kidsAbsolute = true;
            }
            $screenMarkup .= '<section class="screen' . $activeClass . '" data-screen="'
                . htmlspecialchars($name, ENT_QUOTES) . '" style="' . $screenStyle . '">'
                . $this->renderChildren($screen['children'] ?? [], $kidsAbsolute)
                . '</section>';
        }

        $themeCss = $this->theme->cssVars();
        $startAttr = htmlspecialchars($start, ENT_QUOTES);
        $rootStyle = $this->flow
            ? 'width:100%;min-height:100vh;'
            : 'width:' . $width . 'px;height:' . $height . 'px;';

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{$title}</title>
  <style>{$themeCss}</style>
  <link rel="stylesheet" href="assets/app.css">
</head>
<body>
  <div id="app-root" class="app-root" data-start-screen="{$startAttr}" style="{$rootStyle}">
    {$screenMarkup}
  </div>
  <script>
    document.addEventListener("DOMContentLoaded", function () {
      document.querySelectorAll("[data-control='ScrollableForm'], .screen").forEach(function (el) {
        el.scrollTop = 0;
      });
      window.scrollTo(0, 0);
    });
  </script>
  <script src="assets/runtime.js"></script>
</body>
</html>
HTML;
    }

    /**
     * @param array<string, mixed> $ir
     * @return array<string, mixed>
     */
    private function expandComponents(array $ir): array
    {
        $defs = [];
        foreach (is_array($ir['components'] ?? null) ? $ir['components'] : [] as $name => $comp) {
            if (is_array($comp)) {
                $defs[(string) (is_string($name) ? $name : ($comp['name'] ?? ''))] = $comp;
            }
        }
        $screens = is_array($ir['screens'] ?? null) ? $ir['screens'] : [];
        foreach ($screens as $i => $screen) {
            if (is_array($screen)) {
                $screens[$i] = $this->expandNode($screen, $defs);
            }
        }
        $ir['screens'] = $screens;

        return $ir;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, array<string, mixed>> $defs
     * @return array<string, mixed>
     */
    private function expandNode(array $node, array $defs): array
    {
        $comp = (string) ($node['component'] ?? '');
        $kids = is_array($node['children'] ?? null) ? $node['children'] : [];
        if ($comp !== '' && $kids === [] && isset($defs[$comp])) {
            $kids = $this->cloneTree(is_array($defs[$comp]['children'] ?? null) ? $defs[$comp]['children'] : []);
        }
        foreach ($kids as $i => $child) {
            if (is_array($child)) {
                $kids[$i] = $this->expandNode($child, $defs);
            }
        }
        $node['children'] = $kids;

        return $node;
    }

    /**
     * @param list<mixed> $nodes
     * @return list<mixed>
     */
    private function cloneTree(array $nodes): array
    {
        $json = json_encode($nodes);
        if (!is_string($json)) {
            return [];
        }
        $copy = json_decode($json, true);

        return is_array($copy) ? $copy : [];
    }

    /**
     * @param array<string, mixed> $ir
     * @param list<array<string, mixed>> $screens
     */
    private function startScreenName(array $ir, array $screens): string
    {
        $names = [];
        foreach ($screens as $s) {
            $n = (string) ($s['name'] ?? '');
            if ($n !== '') {
                $names[$n] = true;
            }
        }
        // Prefer the home landing page so sidebar navigation is immediately usable.
        foreach (['VCR Home Page', 'VCR / VCN Form'] as $prefer) {
            if (isset($names[$prefer])) {
                return $prefer;
            }
        }
        $start = (string) (($ir['app']['properties']['StartScreen'] ?? '') ?: '');
        $start = trim($start, "='\" \t\n\r");
        if ($start !== '' && $start !== 'Loading Screen' && isset($names[$start])) {
            return $start;
        }
        foreach ($screens as $s) {
            $name = (string) ($s['name'] ?? '');
            if ($name !== '' && $name !== 'Loading Screen') {
                return $name;
            }
        }

        return (string) ($screens[0]['name'] ?? '');
    }

    /**
     * @param list<mixed> $children
     */
    private function renderChildren(array $children, bool $absolute): string
    {
        if ($this->flow) {
            $absolute = false;
        }
        $list = [];
        foreach ($children as $child) {
            if (is_array($child)) {
                $list[] = $child;
            }
        }
        if ($this->flow && count($list) > 1) {
            usort($list, static function (array $a, array $b): int {
                $ap = self::flowChromePriority($a);
                $bp = self::flowChromePriority($b);
                if ($ap !== $bp) {
                    return $ap <=> $bp;
                }
                $ay = (int) (($a['layout']['y'] ?? null) ?: 0);
                $by = (int) (($b['layout']['y'] ?? null) ?: 0);
                // Same visual row in Studio (±24px): labels precede fields so flow
                // reading order matches web forms (PA often parks labels to the right).
                if (abs($ay - $by) > 24) {
                    return $ay <=> $by;
                }
                $aLabel = self::isFlowLabelNode($a);
                $bLabel = self::isFlowLabelNode($b);
                if ($aLabel !== $bLabel) {
                    return $aLabel ? -1 : 1;
                }
                $ax = (int) (($a['layout']['x'] ?? null) ?: 0);
                $bx = (int) (($b['layout']['x'] ?? null) ?: 0);

                return $ax <=> $bx;
            });
        }
        $html = '';
        foreach ($list as $child) {
            $html .= $this->renderControl($child, $absolute);
        }

        return $html;
    }

    /** @param array<string, mixed> $node */
    private function renderControl(array $node, bool $absolute): string
    {
        $props = is_array($node['properties'] ?? null) ? $node['properties'] : [];
        if ($this->isOmitted($props)) {
            return '';
        }

        $rawName = (string) ($node['name'] ?? '');
        // Settings panel (TransparentButtonContainer + Settings*) must stay in the DOM so
        // SettingsButton can reveal it via varTopMenu. Only omit permanent chrome noise.
        if (in_array($rawName, [
            'RequestIDHeader',
            'BugReportingContainer',
            'LoadingContainer',
            'FormValidation',
        ], true)) {
            return '';
        }
        $name = htmlspecialchars($rawName, ENT_QUOTES);
        $role = (string) ($node['role'] ?? 'control');
        $type = strtolower((string) ($node['type'] ?? ''));
        $layout = is_array($node['layout'] ?? null) ? $node['layout'] : [];
        $x = (int) ($layout['x'] ?? 0);
        $y = (int) ($layout['y'] ?? 0);
        $w = (int) ($layout['width'] ?? 0);
        $h = (int) ($layout['height'] ?? 0);
        $dir = (string) ($node['layout_direction'] ?? '');
        $startsHidden = $this->startsHidden($props);

        // 1×1 Studio SetFocus buttons are often mid-section (or even in the wrong
        // section). Named section anchors get a real top-of-section <a id> when the
        // section container is rendered; mid markers keep data-control only.
        if ($role === 'button' && $w <= 2 && $h <= 2) {
            return $this->renderPaMidAnchor($rawName, $name, $absolute, $x, $y)
                . $this->renderChildren($node['children'] ?? [], false);
        }

        $fillFx = (string) ($props['Fill'] ?? '');
        $colorFx = (string) ($props['Color'] ?? '');
        $fill = $fillFx !== '' ? $this->theme->color($fillFx) : '';
        $color = $colorFx !== '' ? $this->theme->color($colorFx) : '';
        $fontSize = $this->numericProp($props['Size'] ?? $props['FontSize'] ?? null);
        $weight = $this->stripFx((string) ($props['FontWeight'] ?? ''));
        $align = strtolower($this->stripFx((string) ($props['Align'] ?? '')));
        $border = $this->theme->color((string) ($props['BorderColor'] ?? ''));
        $borderW = $this->numericProp($props['BorderThickness'] ?? null);
        $borderStyle = strtolower($this->stripFx((string) ($props['BorderStyle'] ?? '')));
        // Group containers often set BorderColor for validation but keep thickness 0 unless
        // BorderStyle.Solid (or an explicit thickness) asks for a hairline.
        if ($borderW === null && $border !== '' && $border !== 'transparent' && str_contains($borderStyle, 'solid')) {
            $borderW = 1.0;
        }
        $radius = $this->numericProp($props['RadiusTopLeft'] ?? $props['RadiusBottomLeft'] ?? null);
        $pad = is_array($node['layout_pad'] ?? null) ? $node['layout_pad'] : null;

        $css = ['box-sizing:border-box'];
        $isHost = $role === 'container'
            || str_contains($type, 'groupcontainer')
            || str_contains($type, 'screen')
            || str_contains($type, 'form');
        if ($this->flow) {
            $css[] = 'position:relative';
            if ($isHost) {
                $css[] = 'width:100%';
                $css[] = 'max-width:100%';
            }
            $fillPortions = (float) ($node['_fill_portions'] ?? 0);
            if ($fillPortions > 0 && !(bool) ($node['_explicit_width'] ?? false)) {
                $css[] = 'flex:' . rtrim(rtrim(sprintf('%.4f', $fillPortions), '0'), '.') . ' 1 0';
            } elseif ($dir !== '') {
                $css[] = 'flex:0 0 auto';
            }
        } elseif ($absolute) {
            $css[] = 'position:absolute';
            $css[] = 'left:' . $x . 'px';
            $css[] = 'top:' . $y . 'px';
        } else {
            $css[] = 'position:relative';
            $fillPortions = (float) ($node['_fill_portions'] ?? 0);
            if ($fillPortions > 0 && !(bool) ($node['_explicit_width'] ?? false)) {
                $css[] = 'flex:' . rtrim(rtrim(sprintf('%.4f', $fillPortions), '0'), '.') . ' 1 0';
                $minW = (int) ceil((float) ($node['_content_min_width'] ?? 0));
                if ($minW > 0) {
                    $css[] = 'min-width:' . $minW . 'px';
                }
            } else {
                $css[] = 'flex:0 0 auto';
            }
        }
        if (!$this->flow) {
            if ($w > 0) {
                $css[] = 'width:' . $w . 'px';
            }
            if ($h > 0) {
                $css[] = 'height:' . $h . 'px';
            }
        } elseif ($role === 'media' || str_contains($type, 'icon') || str_contains($type, 'image')) {
            // Keep design-size caps so logos/icons do not expand to the column width
            // and cover clickable home cards (AppLogo was 45×45 on canvas).
            if ($w > 0) {
                $css[] = 'width:' . $w . 'px';
                $css[] = 'max-width:100%';
            }
            if ($h > 0) {
                $css[] = 'height:' . $h . 'px';
                $css[] = 'max-height:' . $h . 'px';
            }
        } elseif (
            $role === 'input'
            || str_contains($type, 'dropdown')
            || str_contains($type, 'combobox')
            || str_contains($type, 'textinput')
            || str_contains($type, 'numberinput')
            || str_contains($type, 'datepicker')
            || str_contains($type, 'textarea')
        ) {
            // Fill the row after the label instead of keeping Studio field widths.
            $css[] = 'flex:1 1 auto';
            $css[] = 'width:auto';
            $css[] = 'max-width:100%';
            $css[] = 'min-width:0';
            if ($h > 0 && $h <= 48 && (str_contains($type, 'textinput') || str_contains($type, 'dropdown')
                || str_contains($type, 'combobox') || str_contains($type, 'datepicker') || str_contains($type, 'numberinput'))) {
                $css[] = 'min-height:' . $h . 'px';
            } elseif ($h > 48 && (str_contains($type, 'textarea') || ($role === 'input' && $h >= 80))) {
                $css[] = 'min-height:' . min($h, 240) . 'px';
            }
        } elseif ($role === 'label') {
            $css[] = 'flex:0 0 auto';
            $css[] = 'max-width:40%';
        }
        if ($fill !== '' && $fill !== 'transparent') {
            $css[] = 'background:' . $fill;
        }
        if ($color !== '' && $color !== 'transparent') {
            $css[] = 'color:' . $color;
        }
        if ($fontSize !== null) {
            $css[] = 'font-size:' . $fontSize . 'px';
        }
        if (stripos($weight, 'semibold') !== false || stripos($weight, 'bold') !== false) {
            $css[] = 'font-weight:600';
        }
        if (str_contains($align, 'center')) {
            $css[] = 'text-align:center';
        } elseif (str_contains($align, 'right')) {
            $css[] = 'text-align:right';
        }
        if ($border !== '' && $border !== 'transparent' && $borderW !== null && $borderW > 0) {
            $css[] = 'border:' . $borderW . 'px solid ' . $border;
        }
        if ($radius !== null && $radius > 0) {
            $css[] = 'border-radius:' . $radius . 'px';
        }
        $isField = $role === 'input' || str_contains($type, 'dropdown') || str_contains($type, 'combobox')
            || str_contains($type, 'textinput') || str_contains($type, 'numberinput')
            || str_contains($type, 'datepicker');
        if (is_array($pad) && !$isField) {
            foreach (['t' => 'padding-top', 'r' => 'padding-right', 'b' => 'padding-bottom', 'l' => 'padding-left'] as $k => $prop) {
                $n = (int) ($pad[$k] ?? 0);
                if ($n > 0) {
                    $css[] = $prop . ':' . $n . 'px';
                }
            }
        }
        if ($dir === 'horizontal') {
            $css[] = 'display:flex';
            $css[] = 'flex-direction:row';
            $css[] = 'align-items:center';
            $gap = (int) ($node['layout_gap'] ?? 0);
            if ($gap > 0) {
                $css[] = 'gap:' . $gap . 'px';
            }
            if (str_contains((string) ($node['layout_justify'] ?? ''), 'center')) {
                $css[] = 'justify-content:center';
            } elseif (str_contains((string) ($node['layout_justify'] ?? ''), 'end')) {
                $css[] = 'justify-content:flex-end';
            }
        } elseif ($dir === 'vertical') {
            $css[] = 'display:flex';
            $css[] = 'flex-direction:column';
            $gap = (int) ($node['layout_gap'] ?? 0);
            if ($gap > 0) {
                $css[] = 'gap:' . $gap . 'px';
            }
        }
        if (
            !$this->flow
            && (
                str_contains(strtolower((string) ($props['Overflow'] ?? $props['LayoutOverflowY'] ?? '')), 'scroll')
                || str_contains(strtolower((string) ($node['name'] ?? '')), 'scrollable')
            )
        ) {
            $css[] = 'overflow:auto';
            $css[] = 'overflow-anchor:none';
        }

        $text = $this->visibleText($node);
        $kidsAbsolute = $this->flow ? false : ($dir === '');
        $kids = $this->renderChildren(is_array($node['children'] ?? null) ? $node['children'] : [], $kidsAbsolute);
        // Proper HTML anchors at the top of section containers (not leaf controls /
        // mid-pane PA hacks). Only containers — dropdowns like Destination share names.
        $isSectionHost = $role === 'container'
            || str_contains($type, 'groupcontainer')
            || str_contains($type, 'screen');
        $sectionAnchor = $isSectionHost ? SectionAnchors::forSectionName($rawName) : null;
        if ($sectionAnchor !== null && !isset($this->emittedSectionAnchors[$sectionAnchor])) {
            $this->emittedSectionAnchors[$sectionAnchor] = true;
            $kids = $this->sectionTopAnchorHtml($sectionAnchor) . $kids;
        }
        $styleAttr = htmlspecialchars(implode(';', $css), ENT_QUOTES);
        $class = 'ctrl ctrl--' . preg_replace('/[^a-z0-9_-]+/i', '', $role);
        if ($this->flow && $dir === 'horizontal') {
            $class .= ' ps-flow-row';
        }
        if ($startsHidden) {
            $class .= ' is-hidden';
        }

        if (str_contains($type, 'tablist') || $role === 'tabs') {
            return $this->renderTabs($node, $class, $name, $styleAttr);
        }

        if ($role === 'gallery' || str_contains($type, 'gallery')) {
            $gallery = $this->renderGallery($node, $class, $name, $styleAttr);
            if ($gallery !== null) {
                return $gallery;
            }
        }

        if ($role === 'input' || str_contains($type, 'dropdown') || str_contains($type, 'combobox')
            || str_contains($type, 'textinput') || str_contains($type, 'numberinput')
        ) {
            // Admin / Details Office365 user search combos — render as text fields
            // with IR InputTextPlaceholder (no invented user directory).
            $isUserSearchCombo = strcasecmp($name, 'UserSearchCombo') === 0
                || strcasecmp($name, 'ReviewerComboboxInput') === 0
                || (
                    str_contains($type, 'combobox')
                    && preg_match('/SearchUser|Office365Users/i', (string) ($props['Items'] ?? '')) === 1
                );
            if ($isUserSearchCombo) {
                $ph = $this->placeholder($node);

                return '<input class="' . $class . '" data-control="' . $name
                    . '" data-role="input" data-combo="user-search" type="text" placeholder="'
                    . htmlspecialchars($ph, ENT_QUOTES) . '" style="' . $styleAttr . '" />';
            }
            $isDrop = str_contains($type, 'dropdown') || str_contains($type, 'combobox');
            if ($isDrop) {
                $opts = $this->dropdownOptionRecords($props);
                if (preg_match('/Filter$/i', $name) === 1 && ($opts === [] || (string) ($opts[0]['label'] ?? '') !== '')) {
                    array_unshift($opts, ['label' => '', 'id' => '']);
                }
                $inner = '';
                foreach ($opts as $opt) {
                    $label = (string) ($opt['label'] ?? '');
                    $id = (string) ($opt['id'] ?? '');
                    $inner .= '<option'
                        . ($id !== '' ? ' data-id="' . htmlspecialchars($id, ENT_QUOTES) . '"' : '')
                        . '>' . htmlspecialchars($label, ENT_QUOTES) . '</option>';
                }
                if ($inner === '') {
                    $inner = '<option></option>';
                }

                return '<select class="' . $class . '" data-control="' . $name
                    . '" data-role="input" style="' . $styleAttr . '">' . $inner . '</select>';
            }
            if (str_contains($type, 'datepicker')) {
                $defaultFx = $this->stripFx((string) ($props['DefaultDate'] ?? $props['Default'] ?? ''));
                $today = (new \DateTimeImmutable('now'))->format('Y-m-d');
                $isFilter = preg_match('/Filter$/i', $name) === 1;
                $value = $isFilter ? '' : $today;
                $defaultFxAttr = '';
                if ($defaultFx !== '' && $this->looksLikeFormula($defaultFx)) {
                    $defaultFxAttr = ' data-default-fx="' . htmlspecialchars($defaultFx, ENT_QUOTES) . '"';
                }

                return '<input class="' . $class . '" data-control="' . $name
                    . '" data-role="input" type="date" value="' . htmlspecialchars($value, ENT_QUOTES) . '"'
                    . $defaultFxAttr . ' style="' . $styleAttr . '" />';
            }
            $ph = $this->placeholder($node);
            $value = $this->defaultInputValue($node);
            $valueAttr = $value !== '' ? ' value="' . htmlspecialchars($value, ENT_QUOTES) . '"' : '';
            $defaultFx = $this->stripFx((string) ($props['Default'] ?? ''));
            $defaultFxAttr = '';
            if ($value === '' && $defaultFx !== '' && $this->looksLikeFormula($defaultFx)) {
                $defaultFxAttr = ' data-default-fx="' . htmlspecialchars($defaultFx, ENT_QUOTES) . '"';
            }

            return '<input class="' . $class . '" data-control="' . $name
                . '" data-role="input" type="text" placeholder="' . htmlspecialchars($ph, ENT_QUOTES) . '"'
                . $valueAttr . $defaultFxAttr . ' style="' . $styleAttr . '" />';
        }

        if ($role === 'choice' || str_contains($type, 'checkbox') || str_contains($type, 'toggle') || str_contains($type, 'radio')) {
            // Radio groups (ThemeRadio, LanguageRadio) — not checkboxes.
            if (str_contains($type, 'radio') || preg_match('/Radio$/i', (string) ($node['name'] ?? '')) === 1) {
                return $this->renderRadioGroup($node, $class, $name, $styleAttr);
            }
            $labelSource = $text;
            // Classic/modern checkboxes expose the maker caption on Label, while
            // AccessibleLabel is often just the control name ("Delete Confirm Settings Check").
            $propLabel = $this->literalString((string) ($props['Label'] ?? ''));
            if ($propLabel !== '') {
                $labelSource = $propLabel;
            } elseif ($labelSource === '') {
                $labels = is_array($node['labels'] ?? null) ? $node['labels'] : [];
                $labelSource = trim((string) ($labels['Label'] ?? ''));
            }
            $label = htmlspecialchars($labelSource !== '' ? $labelSource : $this->prettyName((string) ($node['name'] ?? '')), ENT_QUOTES);
            $boxBorder = $this->theme->color((string) ($props['CheckboxBorderColor'] ?? $props['BorderColor'] ?? 'gblTheme.CheckboxBorder'));
            if ($boxBorder === '' || $boxBorder === 'transparent') {
                $boxBorder = $this->theme->color('gblTheme.CheckboxBorder');
            }
            if ($boxBorder === '' || $boxBorder === 'transparent') {
                $boxBorder = '#64748b';
            }
            $boxStyle = 'border-color:' . $boxBorder;

            return '<label class="' . $class . ' ctrl--checkbox" data-control="' . $name
                . '" data-role="choice" style="' . $styleAttr . '">'
                . '<input type="checkbox" class="ctrl-check-input" />'
                . '<span class="ctrl-check-box" style="' . htmlspecialchars($boxStyle, ENT_QUOTES) . '" aria-hidden="true"></span>'
                . '<span class="ctrl-check-label">' . $label . '</span></label>';
        }

        if ($role === 'media' || str_contains($type, 'icon') || str_contains($type, 'image')) {
            $inner = $this->mediaInner($node, $text);

            return '<div class="' . $class . ' ctrl--media" data-control="' . $name
                . '" data-role="media" style="' . $styleAttr . '">' . $inner . '</div>';
        }

        // Modern InformationButton — circular (i) that surfaces Content (e.g. fnEmailTokenGuide).
        if (str_contains($type, 'informationbutton') || preg_match('/^InformationButton/i', (string) ($node['name'] ?? '')) === 1) {
            $contentFx = $this->stripFx((string) ($props['Content'] ?? ''));
            $contentAttr = $contentFx !== ''
                ? ' data-info-fx="' . htmlspecialchars($contentFx, ENT_QUOTES) . '"'
                : '';

            return '<button type="button" class="' . $class . ' ctrl--info-btn" data-control="' . $name
                . '" data-role="button"' . $contentAttr . ' style="' . $styleAttr
                . '" title="Information" aria-label="Information">'
                . '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="10" fill="none" stroke="currentColor" stroke-width="1.75"/><path d="M12 10.5v6M12 7.5h.01" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"/></svg>'
                . '</button>';
        }

        // Attachments control chrome (Import Data).
        if (str_contains($type, 'attachments')) {
            return '<div class="' . $class . ' ctrl--attachments" data-control="' . $name
                . '" data-role="attachments" style="' . $styleAttr . '">'
                . '<div class="ctrl-attach-empty">There is nothing attached.</div>'
                . '<button type="button" class="ctrl-attach-btn">'
                . '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21.44 11.05 12.25 20.24a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/></svg>'
                . 'Attach file</button></div>';
        }

        // Power BI embed placeholder (Map / Overview / Import Data).
        if (str_contains($type, 'powerbi')) {
            $border = $this->theme->color((string) ($props['BorderColor'] ?? 'gblTheme.Accent'));
            if ($border === '' || $border === 'transparent') {
                $border = '#2563eb';
            }

            return '<div class="' . $class . ' ctrl--powerbi" data-control="' . $name
                . '" data-role="powerbi" style="' . $styleAttr . ';border:1px solid '
                . htmlspecialchars($border, ENT_QUOTES) . '">'
                . '<div class="ctrl-powerbi-mark" aria-hidden="true">'
                . '<span></span><span></span><span></span></div></div>';
        }

        if ($role === 'button') {
            $appearance = strtolower($this->stripFx((string) ($props['Appearance'] ?? '')));
            $isPrimary = str_contains($appearance, 'primary');
            $isSubmit = stripos((string) ($node['name'] ?? ''), 'submit') !== false;
            $label = $text;
            if ($isSubmit && ($label === '' || strcasecmp($label, 'Top Submit Button') === 0 || str_contains($label, 'Type of Visit'))) {
                $label = 'Submit';
            }
            // True hit-target overlays have no Fill and no visible label. Buttons with Text
            // (Cancel / Save and Close / etc.) must stay visible even without an explicit Fill.
            $ghost = $label === '' && !$isPrimary && !$isSubmit && ($fill === '' || $fill === 'transparent'
                || preg_match('/rgba\(\s*0\s*,\s*0\s*,\s*0\s*,\s*0\s*\)/i', $fill) === 1);
            if (($isPrimary || $isSubmit) && ($fill === '' || $fill === 'transparent')) {
                // Prefer maker BasePaletteColor (Admin Reset/Submit use explicit RGBA palettes).
                $base = $this->theme->color((string) ($props['BasePaletteColor'] ?? ''));
                if ($base !== '' && $base !== 'transparent') {
                    $fill = $base;
                } elseif ($isSubmit) {
                    // Idle form Submit uses gblTheme.Rail in an If(); Studio paints charcoal.
                    $fill = '#1f2937';
                } else {
                    $rail = $this->theme->color('gblTheme.Rail');
                    $fill = ($rail !== '' && $rail !== 'transparent') ? $rail : '#1f2937';
                }
                $css[] = 'background:' . $fill;
                $css[] = 'color:#fff';
                $styleAttr = htmlspecialchars(implode(';', $css), ENT_QUOTES);
            } elseif (!$ghost && ($fill === '' || $fill === 'transparent')) {
                $base = $this->theme->color((string) ($props['BasePaletteColor'] ?? 'gblTheme.SurfaceMuted'));
                if ($base === '' || $base === 'transparent') {
                    $base = '#e2e8f0';
                }
                $ink = $this->theme->color((string) ($props['FontColor'] ?? $props['Color'] ?? 'gblTheme.Text'));
                if ($ink === '' || $ink === 'transparent') {
                    $ink = '#0f172a';
                }
                $css[] = 'background:' . $base;
                $css[] = 'color:' . $ink;
                $styleAttr = htmlspecialchars(implode(';', $css), ENT_QUOTES);
            }
            if ($ghost) {
                $class .= ' ctrl--ghost';
                $css[] = 'background:transparent';
                $css[] = 'color:transparent';
                $css[] = 'border:0';
                $styleAttr = htmlspecialchars(implode(';', $css), ENT_QUOTES);
                $label = '';
            } elseif ($label === '') {
                $label = $isSubmit ? 'Submit' : $this->prettyName((string) ($node['name'] ?? ''));
            }

            $safeKids = (str_contains($kids, '<button') || str_contains($kids, '<select') || str_contains($kids, '<input'))
                ? ''
                : $kids;

            return '<button type="button" class="' . $class . '" data-control="' . $name
                . '" data-role="button" style="' . $styleAttr . '">'
                . htmlspecialchars($label, ENT_QUOTES) . $safeKids . '</button>'
                . ($safeKids === '' ? $kids : '');
        }

        if ($role === 'html') {
            $html = $this->htmlText($node);

            return '<div class="' . $class . '" data-control="' . $name
                . '" data-role="html" style="' . $styleAttr . '">' . $html . $kids . '</div>';
        }

        // RichTextEditor@2.x — Studio paints an HTML editor chrome; Default is usually a LookUp.
        if ($role === 'richtext' || str_contains($type, 'richtext')) {
            return $this->renderRichTextEditor($node, $class, $name, $styleAttr, $kids);
        }

        $inner = '';
        if ($text !== '' && $kids === '') {
            $inner = htmlspecialchars($text, ENT_QUOTES);
        } elseif ($text !== '' && $dir === '') {
            $inner = '<span class="ctrl-text">' . htmlspecialchars($text, ENT_QUOTES) . '</span>';
        }

        return '<div class="' . $class . '" data-control="' . $name
            . '" data-role="' . htmlspecialchars($role, ENT_QUOTES) . '" style="' . $styleAttr . '">'
            . $inner . $kids . '</div>';
    }

    /**
     * Emit Power Apps RichTextEditor chrome (toolbar + editable HtmlText surface).
     *
     * @param array<string, mixed> $node
     */
    private function renderRichTextEditor(
        array $node,
        string $class,
        string $name,
        string $styleAttr,
        string $kids
    ): string {
        $props = is_array($node['properties'] ?? null) ? $node['properties'] : [];
        $defaultFx = $this->stripFx((string) ($props['Default'] ?? $props['HtmlText'] ?? ''));
        $border = $this->theme->color((string) ($props['BorderColor'] ?? ''));
        if ($border === '' || $border === 'transparent') {
            $border = '#00126b';
        }
        $defaultAttr = $defaultFx !== ''
            ? ' data-default-fx="' . htmlspecialchars($defaultFx, ENT_QUOTES) . '"'
            : '';

        $toolbar = '<div class="ctrl-rte-toolbar" aria-hidden="true">'
            . '<span class="ctrl-rte-tool">Format</span>'
            . '<button type="button" class="ctrl-rte-btn" data-rte-cmd="bold" title="Bold"><b>B</b></button>'
            . '<button type="button" class="ctrl-rte-btn" data-rte-cmd="italic" title="Italic"><i>I</i></button>'
            . '<button type="button" class="ctrl-rte-btn" data-rte-cmd="underline" title="Underline"><u>U</u></button>'
            . '<button type="button" class="ctrl-rte-btn" data-rte-cmd="createLink" title="Link">Link</button>'
            . '<button type="button" class="ctrl-rte-btn" data-rte-cmd="unlink" title="Unlink">Unlink</button>'
            . '<button type="button" class="ctrl-rte-btn" data-rte-cmd="removeFormat" title="Clear formatting">Tx</button>'
            . '<button type="button" class="ctrl-rte-btn" data-rte-cmd="insertUnorderedList" title="Bulleted list">•</button>'
            . '<button type="button" class="ctrl-rte-btn" data-rte-cmd="insertOrderedList" title="Numbered list">1.</button>'
            . '</div>';

        return '<div class="' . $class . ' ctrl--richtext" data-control="' . $name
            . '" data-role="richtext"' . $defaultAttr . ' style="' . $styleAttr
            . ';border:2px solid ' . htmlspecialchars($border, ENT_QUOTES) . '">'
            . $toolbar
            . '<div class="ctrl-rte-body" contenteditable="true" data-prop="HtmlText"></div>'
            . $kids . '</div>';
    }

    /**
     * @param array<string, mixed> $node
     */
    private function renderRadioGroup(array $node, string $class, string $name, string $styleAttr): string
    {
        $props = is_array($node['properties'] ?? null) ? $node['properties'] : [];
        $opts = $this->dropdownOptions($props);
        if ($opts === [] || $opts === ['']) {
            $opts = ['Light', 'Dark'];
        }
        $default = 'Light';
        $defFx = $this->stripFx((string) ($props['DefaultSelectedItems'] ?? $props['Default'] ?? ''));
        if (preg_match('/gblDarkMode/i', $defFx) === 1 && preg_match('/"Dark"/', $defFx) === 1) {
            $default = 'Light'; // idle gblDarkMode=false
        } elseif (preg_match('/\["((?:\\\\.|[^"])*)"\]/', $defFx, $m)) {
            $default = stripcslashes($m[1]);
        }
        // Unique name= per clone so component instances do not fight for one radio group.
        $this->radioSeq++;
        $group = htmlspecialchars((string) ($node['name'] ?? 'radio') . '__' . $this->radioSeq, ENT_QUOTES);
        $inner = '';
        foreach ($opts as $opt) {
            if ($opt === '') {
                continue;
            }
            $id = htmlspecialchars($group . '_' . preg_replace('/\W+/', '_', $opt), ENT_QUOTES);
            $val = htmlspecialchars($opt, ENT_QUOTES);
            $checked = strcasecmp($opt, $default) === 0 ? ' checked' : '';
            $inner .= '<label class="ctrl-radio-option" for="' . $id . '">'
                . '<input type="radio" class="ctrl-radio-input" name="' . $group . '" id="' . $id
                . '" value="' . $val . '"' . $checked . ' />'
                . '<span class="ctrl-radio-label">' . $val . '</span></label>';
        }

        return '<div class="' . $class . ' ctrl--radio" data-control="' . $name
            . '" data-role="choice" style="' . $styleAttr . '">' . $inner . '</div>';
    }

    /**
     * @param array<string, mixed> $node
     */
    private function renderTabs(array $node, string $class, string $name, string $styleAttr): string
    {
        $tabs = $this->tabEntries($node);
        $inner = '';
        $first = true;
        foreach ($tabs as $tab) {
            $active = $first ? ' ctrl-tab--active' : '';
            $first = false;
            $anchor = htmlspecialchars((string) ($tab['anchor'] ?? ''), ENT_QUOTES);
            $label = htmlspecialchars((string) ($tab['label'] ?? ''), ENT_QUOTES);
            $inner .= '<button type="button" class="ctrl-tab' . $active . '" data-anchor="'
                . $anchor . '">' . $label . '</button>';
        }

        return '<div class="' . $class . ' ctrl--tabs" data-control="' . $name
            . '" data-role="tabs" style="' . $styleAttr . '">' . $inner . '</div>';
    }

    /**
     * @param array<string, mixed> $node
     */
    private function renderGallery(array $node, string $class, string $name, string $styleAttr): ?string
    {
        $itemsFx = $this->stripFx((string) (($node['properties']['Items'] ?? '') ?: ''));
        $rows = [];
        foreach ($this->collections as $colName => $colRows) {
            if ($itemsFx === $colName || preg_match('/\b' . preg_quote($colName, '/') . '\b/', $itemsFx) === 1) {
                $rows = $colRows;
                break;
            }
        }
        // Literal string tables: ["Help", "User Roles", ...]
        if ($rows === [] && preg_match('/^\[(.*)\]$/s', trim($itemsFx), $m) === 1) {
            if (preg_match_all('/"((?:\\\\.|[^"])*)"/', $m[1], $q) > 0) {
                foreach ($q[1] as $label) {
                    $rows[] = ['Value' => stripcslashes($label), 'Name' => stripcslashes($label)];
                }
            }
        }
        // Only the sidebar rail uses icon buttons. Form galleries (colSites / colVisitors /
        // LookupGallery) keep their Studio template children so fields render.
        $isNav = false;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            if ((string) ($row['Screen'] ?? '') !== '') {
                $isNav = true;
                break;
            }
        }
        if ($isNav) {
            if ($rows === []) {
                return null;
            }
            // Idle preview: varCurrentUser.role = Admin, fnIsAcknowledged = false
            // Matches Filter(SideMenuSecondTable, (Permission = role || Member) && (Name <> Emergency || fnIsAcknowledged))
            $filtered = [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $perm = (string) ($row['Permission'] ?? 'Member');
                if ($perm !== 'Member' && strcasecmp($perm, 'Admin') !== 0) {
                    continue;
                }
                $label = (string) ($row['Name'] ?? $row['Label'] ?? $row['Value'] ?? '');
                if (strcasecmp($label, 'Emergency Contact') === 0) {
                    continue;
                }
                $filtered[] = $row;
            }
            $rows = $filtered;
            $inner = '';
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $label = (string) ($row['Name'] ?? $row['Label'] ?? $row['Value'] ?? '');
                $screen = (string) ($row['Screen'] ?? '');
                // Prefer hydrated SVG markup from Formulas SideMenuSecondTable when present.
                $imageKey = (string) ($row['Image'] ?? '');
                $icon = '';
                if (str_starts_with(ltrim($imageKey), '<svg')) {
                    $icon = $imageKey;
                } elseif ($imageKey !== '' && isset($this->formulaIcons[$imageKey])) {
                    $icon = $this->formulaIcons[$imageKey];
                } else {
                    $icon = $this->sidebarGlyph($label, $imageKey);
                }
                $navAttr = $screen !== ''
                    ? ' data-navigate="' . htmlspecialchars($screen, ENT_QUOTES) . '"'
                    : '';
                $inner .= '<button type="button" class="ctrl-navicon" title="'
                    . htmlspecialchars($label, ENT_QUOTES) . '"' . $navAttr . '>' . $icon . '</button>';
            }
            if ($inner === '') {
                return null;
            }

            return '<div class="' . $class . ' ctrl--gallery ctrl--sidebar-rail" data-control="'
                . $name . '" data-role="gallery" style="' . $styleAttr . '">' . $inner . '</div>';
        }

        // Settings / admin option galleries: expand template button per Items row.
        if ($rows !== [] && $name === 'AppSettingsGallery') {
            $inner = '';
            $first = true;
            foreach ($rows as $i => $row) {
                if (!is_array($row)) {
                    continue;
                }
                $label = (string) ($row['Value'] ?? $row['Name'] ?? $row['Label'] ?? '');
                if ($label === '') {
                    continue;
                }
                $active = $first ? ' is-selected' : '';
                $first = false;
                $inner .= '<button type="button" class="ctrl ctrl--button ctrl-settings-pill'
                    . $active . '" data-control="Button11" data-role="button" data-gallery-item="'
                    . htmlspecialchars($label, ENT_QUOTES) . '" data-gallery="'
                    . htmlspecialchars($name, ENT_QUOTES) . '">'
                    . htmlspecialchars($label, ENT_QUOTES) . '</button>';
            }
            if ($inner === '') {
                return null;
            }

            return '<div class="' . $class . ' ctrl--gallery ctrl--settings-pills" data-control="'
                . $name . '" data-role="gallery" data-selected="'
                . htmlspecialchars((string) ($rows[0]['Value'] ?? $rows[0]['Name'] ?? 'Help'), ENT_QUOTES)
                . '" style="' . $styleAttr . '">' . $inner . '</div>';
        }

        // Acknowledgers gallery: Sort(varL1Approvers, ...) — seed IRL row from app formulas.
        if ($rows === [] && preg_match('/\bvarL1Approvers\b/', $itemsFx) === 1) {
            $rows = [['l1' => 'IRL', 'email' => '']];
        }
        if ($rows !== [] && $name === 'L1ApproversGallery') {
            $inner = '';
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $l1 = htmlspecialchars((string) ($row['l1'] ?? $row['L1'] ?? ''), ENT_QUOTES);
                $email = htmlspecialchars((string) ($row['email'] ?? $row['Email'] ?? ''), ENT_QUOTES);
                $inner .= '<div class="ctrl-gallery-row ctrl-acknowledger-row" data-l1="' . $l1 . '" data-email="' . $email . '">'
                    . '<span class="ctrl-ack-l1">' . $l1 . '</span>'
                    . '<input class="ctrl ctrl--input" data-control="Acknowledger" data-role="input" type="text" value="'
                    . $email . '" data-default="' . $email . '" placeholder="Adviser; assistant@... (semicolon-separated)" />'
                    . '<button type="button" class="ctrl ctrl--button" data-control="btnResetAcknowledger" data-role="button">Reset</button>'
                    . '<button type="button" class="ctrl ctrl--button" data-control="btnSubmitAcknowledger" data-role="button">Submit</button>'
                    . '</div>';
            }
            if ($inner === '') {
                return null;
            }

            return '<div class="' . $class . ' ctrl--gallery ctrl--acknowledgers" data-control="'
                . $name . '" data-role="gallery" style="' . $styleAttr . '">' . $inner . '</div>';
        }

        // User Roles gallery: Items → varTempUser (loaded from App Users in Studio).
        if ($rows === [] && preg_match('/\bvarTempUser\b/', $itemsFx) === 1) {
            $rows = [[
                'user' => 'preview@local',
                'role' => 'Admin',
                'preferences' => [
                    'theme' => 'light',
                    'show_delete_confirm' => 'true',
                    'show_notif_confirm' => 'true',
                ],
            ]];
        }
        if ($rows !== [] && $name === 'UserGallery') {
            // Dropdown3 Items = UserRoles formula (["Member", "Admin"] in Formulas).
            $roleOpts = $this->formulaStringList('UserRoles');
            if ($roleOpts === []) {
                $roleOpts = ['Member', 'Admin'];
            }
            $inner = '';
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $user = htmlspecialchars((string) ($row['user'] ?? $row['User'] ?? ''), ENT_QUOTES);
                $role = (string) ($row['role'] ?? $row['Role'] ?? 'Admin');
                $prefs = is_array($row['preferences'] ?? null) ? $row['preferences'] : [];
                $showDel = htmlspecialchars((string) ($prefs['show_delete_confirm'] ?? 'true'), ENT_QUOTES);
                $showNotif = htmlspecialchars((string) ($prefs['show_notif_confirm'] ?? 'true'), ENT_QUOTES);
                // Label7_267 Text formula from IR.
                $prefLabel = 'Show delete confirmation: ' . $showDel
                    . ', Show notification confirmation: ' . $showNotif;
                $optsHtml = '';
                foreach ($roleOpts as $opt) {
                    $sel = strcasecmp($opt, $role) === 0 ? ' selected' : '';
                    $optsHtml .= '<option' . $sel . '>' . htmlspecialchars($opt, ENT_QUOTES) . '</option>';
                }
                $inner .= '<div class="ctrl-gallery-row ctrl-user-row" data-user="' . $user . '" data-role-value="'
                    . htmlspecialchars($role, ENT_QUOTES) . '">'
                    . '<span class="ctrl-user-email">' . $user . '</span>'
                    . '<select class="ctrl ctrl--input" data-control="Dropdown3" data-role="input">' . $optsHtml . '</select>'
                    . '<span class="ctrl-user-pref">' . $prefLabel . '</span>'
                    . '</div>';
            }
            if ($inner === '') {
                return null;
            }

            return '<div class="' . $class . ' ctrl--gallery ctrl--user-roles" data-control="'
                . $name . '" data-role="gallery" style="' . $styleAttr . '">' . $inner . '</div>';
        }

        return null;
    }

    private function sidebarGlyph(string $label, string $imageKey = ''): string
    {
        // Prefer named icons authored in app Formulas (IconAdminSidebar, etc.).
        if ($imageKey !== '' && isset($this->formulaIcons[$imageKey])) {
            return $this->formulaIcons[$imageKey];
        }
        if (strcasecmp($imageKey, 'IconAdminSidebar') === 0 || strcasecmp($imageKey, 'IconAdminImage') === 0) {
            if (isset($this->formulaIcons[$imageKey])) {
                return $this->formulaIcons[$imageKey];
            }
        }
        $l = strtolower($label);
        if (str_contains($l, 'home')) {
            return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m3 10 9-7 9 7v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" fill="none" stroke="currentColor" stroke-width="1.75"/><path d="M9 21V11h6v10" fill="none" stroke="currentColor" stroke-width="1.75"/></svg>';
        }
        if (str_contains($l, 'form') || str_contains($l, 'vcr')) {
            return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m12 3 9 5-9 5-9-5 9-5z" fill="none" stroke="currentColor" stroke-width="1.75"/><path d="m3 13 9 5 9-5M3 18l9 5 9-5" fill="none" stroke="currentColor" stroke-width="1.75"/></svg>';
        }
        if (str_contains($l, 'admin')) {
            return $this->formulaIcons['IconAdminSidebar']
                ?? '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9.8 2.2H7.2a.8.8 0 0 0-.8.8V3.8h4.4V3a.8.8 0 0 0-.8-.8z" fill="none" stroke="currentColor" stroke-width="1.75"/><rect x="3.8" y="3.8" width="10.2" height="15" rx="1.3" fill="none" stroke="currentColor" stroke-width="1.75"/><path d="M6.2 8.8h5.2M6.2 11.4h5.2M6.2 14h3.2" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"/><circle cx="16.8" cy="16.8" r="6.6" fill="#111827"/><path fill-rule="evenodd" fill="#fff" d="M16.046 13.536h1.508l-.065.861 1.048.605.713-.487.754 1.306-.778.374v1.21l.778.374-.754 1.306-.713-.487-1.048.605.065.861h-1.508l.065-.861-1.048-.605-.713.487-.754-1.306.778-.374v-1.21l-.778-.374.754-1.306.713.487 1.048-.605-.065-.861zm1.804 3.264a1.05 1.05 0 1 0-2.1 0 1.05 1.05 0 0 0 2.1 0z"/></svg>';
        }
        if (str_contains($l, 'emergenc')) {
            return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 4 19h16L12 3z" fill="none" stroke="currentColor" stroke-width="1.75"/><path d="M12 10v4M12 16.5v.5" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"/></svg>';
        }

        return '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="7" fill="none" stroke="currentColor" stroke-width="1.75"/></svg>';
    }

    /**
     * Pull named SVG icons out of app Formulas (IconAdminSidebar / IconAdminImage)
     * and inline EncodeUrl icons authored on SideMenuSecondTable / HomePageCardIcons.
     *
     * @param array<string, mixed> $ir
     * @return array<string, string>
     */
    private function extractFormulaIcons(array $ir): array
    {
        $formulas = $this->formulasBlob !== '' ? $this->formulasBlob : $this->findFormulasBlob($ir);
        if ($formulas === '') {
            return [];
        }
        $out = [];
        foreach (['IconAdminSidebar', 'IconAdminImage'] as $name) {
            $svg = $this->decodeEncodeUrlSvg($formulas, $name);
            if ($svg === '') {
                continue;
            }
            // Prefer currentColor for sidebar strokes so dark mode inherits.
            if ($name === 'IconAdminSidebar') {
                $svg = str_replace("stroke='#111827'", 'stroke="currentColor"', $svg);
                $svg = str_replace('stroke="#111827"', 'stroke="currentColor"', $svg);
            }
            $out[$name] = $svg;
        }

        // SideMenuSecondTable / HomePageCardIcons rows may embed EncodeUrl SVGs or named refs.
        foreach (['SideMenuSecondTable' => 'Name', 'HomePageCardIcons' => 'Key'] as $table => $labelKey) {
            if (preg_match('/' . preg_quote($table, '/') . '\s*=\s*\[(.*?)\];/s', $formulas, $tm) !== 1) {
                continue;
            }
            $body = $tm[1];
            if (preg_match_all(
                '/\{\s*(?:Screen:\s*\'([^\']*)\'\s*,\s*)?' . preg_quote($labelKey, '/')
                . ':\s*"([^"]+)"\s*,\s*Image:\s*(.*?)(?:\s*,\s*Permission:\s*"([^"]*)")?\s*\}/s',
                $body,
                $rows,
                PREG_SET_ORDER
            ) > 0) {
                foreach ($rows as $row) {
                    $label = $row[2];
                    $imageExpr = trim($row[3]);
                    $svg = '';
                    if (preg_match('/^Icon[A-Za-z0-9_]+$/', $imageExpr) === 1) {
                        $svg = $out[$imageExpr] ?? $this->decodeEncodeUrlSvg($formulas, $imageExpr);
                    } elseif (str_contains($imageExpr, 'EncodeUrl')) {
                        $svg = $this->materializeEncodeUrlExpr($imageExpr);
                        if ($table === 'SideMenuSecondTable' && $svg !== '') {
                            $svg = str_replace("stroke='#111827'", 'stroke="currentColor"', $svg);
                            $svg = str_replace('stroke="#111827"', 'stroke="currentColor"', $svg);
                        }
                    }
                    if ($svg !== '') {
                        $out[$table . ':' . $label] = $svg;
                        if (preg_match('/^Icon[A-Za-z0-9_]+$/', $imageExpr) === 1 && !isset($out[$imageExpr])) {
                            $out[$imageExpr] = $svg;
                        }
                    }
                }
            }
        }

        return $out;
    }

    /**
     * IR collection export shreds EncodeUrl Image formulas into broken keys.
     * Rehydrate SideMenuSecondTable / HomePageCardIcons Image from Formulas.
     */
    private function hydrateNavCollectionsFromFormulas(): void
    {
        if ($this->formulasBlob === '') {
            return;
        }
        foreach (['SideMenuSecondTable' => 'Name', 'HomePageCardIcons' => 'Key'] as $table => $labelKey) {
            if (!isset($this->collections[$table]) || !is_array($this->collections[$table])) {
                continue;
            }
            if (preg_match('/' . preg_quote($table, '/') . '\s*=\s*\[(.*?)\];/s', $this->formulasBlob, $tm) !== 1) {
                continue;
            }
            $byLabel = [];
            if (preg_match_all(
                '/\{\s*(?:Screen:\s*\'([^\']*)\'\s*,\s*)?' . preg_quote($labelKey, '/')
                . ':\s*"([^"]+)"\s*,\s*Image:\s*(.*?)(?:\s*,\s*Permission:\s*"([^"]*)")?\s*\}/s',
                $tm[1],
                $rows,
                PREG_SET_ORDER
            ) > 0) {
                foreach ($rows as $row) {
                    $label = $row[2];
                    $imageExpr = trim($row[3]);
                    if (preg_match('/^Icon[A-Za-z0-9_]+$/', $imageExpr) === 1) {
                        $byLabel[$label] = $imageExpr;
                    } elseif (str_contains($imageExpr, 'EncodeUrl')) {
                        $svg = $this->materializeEncodeUrlExpr($imageExpr);
                        if ($svg !== '' && $table === 'SideMenuSecondTable') {
                            $svg = str_replace("stroke='#111827'", 'stroke="currentColor"', $svg);
                            $svg = str_replace('stroke="#111827"', 'stroke="currentColor"', $svg);
                        }
                        $byLabel[$label] = $svg !== '' ? $svg : $imageExpr;
                    }
                }
            }
            foreach ($this->collections[$table] as $i => $row) {
                if (!is_array($row)) {
                    continue;
                }
                $label = (string) ($row[$labelKey] ?? $row['Name'] ?? $row['Key'] ?? '');
                if ($label !== '' && isset($byLabel[$label])) {
                    $this->collections[$table][$i]['Image'] = $byLabel[$label];
                    unset($this->collections[$table][$i]['http']);
                } elseif ($label !== '' && isset($this->formulaIcons[$table . ':' . $label])) {
                    $this->collections[$table][$i]['Image'] = $this->formulaIcons[$table . ':' . $label];
                    unset($this->collections[$table][$i]['http']);
                }
            }
        }
    }

    /** Decode `Name = "data:image/svg+xml," & EncodeUrl( ... );` from Formulas. */
    private function decodeEncodeUrlSvg(string $formulas, string $name): string
    {
        if (preg_match(
            '/' . preg_quote($name, '/') . '\s*=\s*"data:image\/svg\+xml,"\s*&\s*EncodeUrl\(\s*(.*?)\s*\)\s*;/s',
            $formulas,
            $m
        ) !== 1) {
            return '';
        }

        return $this->materializeEncodeUrlExpr($m[1]);
    }

    /** Resolve theme token concatenations inside an EncodeUrl(...) body to SVG markup. */
    private function materializeEncodeUrlExpr(string $body): string
    {
        // Body may be the full `"data:..." & EncodeUrl( ... )` or just the EncodeUrl interior.
        if (preg_match('/EncodeUrl\(\s*(.*?)\s*\)\s*$/s', $body, $m) === 1) {
            $body = $m[1];
        }
        $body = preg_replace('/"\s*&\s*gblTheme\.IconStroke\s*&\s*"/', '#111827', $body) ?? $body;
        $body = preg_replace('/"\s*&\s*gblTheme\.IconBadgeFill\s*&\s*"/', '#111827', $body) ?? $body;
        $body = preg_replace('/"\s*&\s*gblTheme\.IconBadgeInner\s*&\s*"/', '#FFFFFF', $body) ?? $body;
        $parts = [];
        if (preg_match_all('/"((?:\\\\.|[^"])*)"/', $body, $q) > 0) {
            foreach ($q[1] as $p) {
                $parts[] = stripcslashes($p);
            }
        }
        $svg = implode('', $parts);

        return ($svg !== '' && str_starts_with($svg, '<svg')) ? $svg : '';
    }

    /** @param array<string, mixed> $node */
    private function findFormulasBlob(array $node): string
    {
        if (isset($node['Formulas']) && is_string($node['Formulas'])) {
            return $node['Formulas'];
        }
        foreach ($node as $v) {
            if (is_array($v)) {
                $found = $this->findFormulasBlob($v);
                if ($found !== '') {
                    return $found;
                }
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $node
     */
    private function mediaInner(array $node, string $text): string
    {
        $props = is_array($node['properties'] ?? null) ? $node['properties'] : [];
        $icon = $this->stripFx((string) ($props['Icon'] ?? ''));
        $iconKey = trim($icon, "='\"");
        // Classic Icon.X and Modern "Info" / Hamburger enums.
        $classic = $this->classicIconSvg($iconKey !== '' ? $iconKey : $icon);
        if ($classic !== null) {
            return $classic;
        }
        if (stripos($icon, 'Hamburger') !== false) {
            return '<svg class="ctrl-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>';
        }
        $image = $this->stripFx((string) ($props['Image'] ?? ''));
        $imageKey = trim($image, "='\"");
        if (isset($this->media[$imageKey])) {
            return '<img src="' . htmlspecialchars($this->media[$imageKey], ENT_QUOTES) . '" alt="'
                . htmlspecialchars($text !== '' ? $text : $imageKey, ENT_QUOTES) . '" />';
        }
        // Home action cards: LookUp(HomePageCardIcons, Key = "New|Edit|Admin").Image
        // Prefer Formulas (IconAdminImage / inline EncodeUrl SVGs) over legacy media files.
        if (preg_match('/HomePageCardIcons.*?Key\s*=\s*"(New|Edit|Admin)"/is', $image, $m)
            || preg_match('/Key\s*=\s*"(New|Edit|Admin)".*?HomePageCardIcons/is', $image, $m)
        ) {
            $key = $m[1];
            // Hydrated collection Image may be a named formula or raw <svg>.
            foreach ($this->collections['HomePageCardIcons'] ?? [] as $row) {
                if (!is_array($row) || (string) ($row['Key'] ?? '') !== $key) {
                    continue;
                }
                $img = (string) ($row['Image'] ?? '');
                if (str_starts_with(ltrim($img), '<svg')) {
                    return preg_replace('/^<svg\b/', '<svg class="ctrl-icon ctrl-icon--card"', ltrim($img), 1) ?? $img;
                }
                if ($img !== '' && isset($this->formulaIcons[$img])) {
                    $svg = $this->formulaIcons[$img];

                    return preg_replace('/^<svg\b/', '<svg class="ctrl-icon ctrl-icon--card"', $svg, 1) ?? $svg;
                }
                break;
            }
            if (isset($this->formulaIcons['HomePageCardIcons:' . $key])) {
                $svg = $this->formulaIcons['HomePageCardIcons:' . $key];

                return preg_replace('/^<svg\b/', '<svg class="ctrl-icon ctrl-icon--card"', $svg, 1) ?? $svg;
            }
            if (strcasecmp($key, 'Admin') === 0 && isset($this->formulaIcons['IconAdminImage'])) {
                $svg = $this->formulaIcons['IconAdminImage'];

                return preg_replace('/^<svg\b/', '<svg class="ctrl-icon ctrl-icon--card"', $svg, 1) ?? $svg;
            }

            return $this->homeCardGlyph($key);
        }
        if (strcasecmp($imageKey, 'User().Image') === 0 || str_contains($image, 'User().Image')) {
            return '<span class="ctrl-avatar" aria-hidden="true"></span>';
        }
        // Avoid painting AccessibleLabel placeholders like "Icon1 67" / "New Request Image".
        if ($text !== '' && !$this->looksLikeFormula($text)
            && !preg_match('/\b(Image|Icon)\b/i', $text)
            && !preg_match('/^Icon\d/i', $text)
        ) {
            return htmlspecialchars($text, ENT_QUOTES);
        }

        return '<svg class="ctrl-icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="7" fill="none" stroke="currentColor" stroke-width="1.75"/></svg>';
    }

    /** Map Classic Icon.Search / Icon.Edit / Modern "Info" enums to SVG chrome. */
    private function classicIconSvg(string $iconKey): ?string
    {
        $k = preg_replace('/^Icon\./i', '', trim($iconKey, "='\"")) ?? $iconKey;
        $k = strtolower($k);
        if ($k === '' || str_starts_with($k, '//') || str_contains($k, 'if(')) {
            return null;
        }
        if ($k === 'info' || $k === 'information') {
            return '<svg class="ctrl-icon ctrl-icon--info" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="10" fill="none" stroke="#2563eb" stroke-width="1.75"/><path d="M12 10.5v6M12 7.5h.01" fill="none" stroke="#2563eb" stroke-width="1.75" stroke-linecap="round"/></svg>';
        }
        if ($k === 'search') {
            return '<svg class="ctrl-icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="6.5" fill="none" stroke="currentColor" stroke-width="1.75"/><path d="m16 16 4 4" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"/></svg>';
        }
        if ($k === 'edit' || $k === 'editpencil') {
            return '<svg class="ctrl-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20h4L18.5 9.5a2.1 2.1 0 0 0-3-3L5 17v3z" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linejoin="round"/><path d="m13.5 6.5 3 3" fill="none" stroke="currentColor" stroke-width="1.75"/></svg>';
        }
        if ($k === 'blocked' || $k === 'cancel' || $k === 'cancelbadge') {
            return '<svg class="ctrl-icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8" fill="none" stroke="currentColor" stroke-width="1.75"/><path d="m9 9 6 6M15 9l-6 6" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"/></svg>';
        }
        if ($k === 'hamburger' || $k === 'hamburgericon') {
            return '<svg class="ctrl-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>';
        }

        return null;
    }

    private function homeCardGlyph(string $key): string
    {
        $stroke = 'currentColor';
        if (strcasecmp($key, 'New') === 0) {
            return '<svg class="ctrl-icon ctrl-icon--card" viewBox="0 0 48 48" aria-hidden="true">'
                . '<rect x="14" y="10" width="20" height="28" rx="2.5" fill="none" stroke="' . $stroke . '" stroke-width="2"/>'
                . '<path d="M19 20h10M19 26h10M19 32h7" fill="none" stroke="' . $stroke . '" stroke-width="2" stroke-linecap="round"/>'
                . '<circle cx="34" cy="34" r="7.25" fill="#2563eb"/><path d="M34 30v8M30 34h8" stroke="#fff" stroke-width="2" stroke-linecap="round"/>'
                . '</svg>';
        }
        if (strcasecmp($key, 'Edit') === 0) {
            return '<svg class="ctrl-icon ctrl-icon--card" viewBox="0 0 48 48" aria-hidden="true">'
                . '<rect x="12" y="10" width="20" height="28" rx="2.5" fill="none" stroke="' . $stroke . '" stroke-width="2"/>'
                . '<path d="M28 30l8-8 4 4-8 8h-4v-4z" fill="none" stroke="' . $stroke . '" stroke-width="2" stroke-linejoin="round"/>'
                . '</svg>';
        }
        if (isset($this->formulaIcons['IconAdminImage'])) {
            $svg = $this->formulaIcons['IconAdminImage'];
            // Scale/class for card size.
            if (str_starts_with($svg, '<svg')) {
                return preg_replace('/^<svg\b/', '<svg class="ctrl-icon ctrl-icon--card"', $svg, 1) ?? $svg;
            }
        }

        return '<svg class="ctrl-icon ctrl-icon--card" viewBox="0 0 48 48" aria-hidden="true">'
            . '<path d="M18 7h10a2 2 0 0 1 2 2v2H16V9a2 2 0 0 1 2-2z" fill="none" stroke="' . $stroke . '" stroke-width="2"/>'
            . '<rect x="13" y="9" width="19" height="27" rx="2.5" fill="none" stroke="' . $stroke . '" stroke-width="2"/>'
            . '<path d="M18 19h9M18 25h9M18 31h6" fill="none" stroke="' . $stroke . '" stroke-width="2" stroke-linecap="round"/>'
            . '<circle cx="34" cy="34" r="8" fill="#111827"/>'
            . '<path fill="#fff" d="M34 29.2l.9 1.9 2.1.3-1.5 1.5.4 2.1L34 33.9l-1.9 1.1.4-2.1-1.5-1.5 2.1-.3z"/>'
            . '</svg>';
    }

    /**
     * Resolve a named Formulas list like UserRoles = ["Member", "Admin"];
     *
     * @return list<string>
     */
    private function formulaStringList(string $name): array
    {
        if ($name === '' || $this->formulasBlob === '') {
            return [];
        }
        if (preg_match(
            '/' . preg_quote($name, '/') . '\s*=\s*\[(.*?)\]\s*;/s',
            $this->formulasBlob,
            $fm
        ) !== 1) {
            return [];
        }
        $opts = [];
        if (preg_match_all('/"((?:\\\\.|[^"])*)"/', $fm[1], $q) > 0) {
            foreach ($q[1] as $opt) {
                $opts[] = stripcslashes($opt);
            }
        }

        return $opts;
    }

    /**
     * @param array<string, mixed> $props
     * @return list<string>
     */
    private function dropdownOptions(array $props): array
    {
        $items = $this->stripFx((string) ($props['Items'] ?? ''));
        if (preg_match('/^\[(.*)\]$/s', $items, $m)) {
            $opts = [];
            if (preg_match_all('/"((?:\\\\.|[^"])*)"/', $m[1], $q)) {
                foreach ($q[1] as $opt) {
                    $opts[] = stripcslashes($opt);
                }
            }

            return $opts !== [] ? $opts : [''];
        }

        // Named formula tables: ApprovalChoices = [Blank(), "Acknowledge", ...]
        if (preg_match('/^[A-Za-z_][\w]*$/', $items) === 1) {
            $named = $this->formulaStringList($items);
            if ($named !== []) {
                // Classic dropdowns often paint a blank first choice.
                array_unshift($named, '');

                return $named;
            }
        }

        $field = 'Value';
        $valueFx = $this->stripFx((string) ($props['Items.Value'] ?? $props['Value'] ?? ''));
        if ($valueFx !== '' && preg_match('/^[A-Za-z_]\w*$/', $valueFx)) {
            $field = $valueFx;
        }
        foreach ($this->collections as $colName => $rows) {
            if ($items === $colName || str_contains($items, $colName)) {
                $opts = [''];
                foreach ($rows as $row) {
                    $lab = (string) ($row[$field] ?? $row['Name'] ?? $row['Label'] ?? $row['Value'] ?? $row['Acronym'] ?? '');
                    if ($lab !== '') {
                        $opts[] = $lab;
                    }
                }

                return $opts !== [''] ? $opts : [''];
            }
        }

        return [''];
    }

    /**
     * @param array<string, mixed> $props
     * @return list<array{label:string,id:string}>
     */
    private function dropdownOptionRecords(array $props): array
    {
        $labels = $this->dropdownOptions($props);
        $items = $this->stripFx((string) ($props['Items'] ?? ''));
        $field = 'Value';
        $valueFx = $this->stripFx((string) ($props['Items.Value'] ?? $props['Value'] ?? ''));
        if ($valueFx !== '' && preg_match('/^[A-Za-z_]\w*$/', $valueFx)) {
            $field = $valueFx;
        }
        foreach ($this->collections as $colName => $rows) {
            if ($items === $colName || ($items !== '' && str_contains($items, $colName))) {
                $out = [['label' => '', 'id' => '']];
                foreach ($rows as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $lab = (string) ($row[$field] ?? $row['Name'] ?? $row['Label'] ?? $row['Value'] ?? $row['Acronym'] ?? '');
                    $id = (string) ($row['ID'] ?? $row['Id'] ?? '');
                    if ($lab !== '' || $id !== '') {
                        $out[] = ['label' => $lab, 'id' => $id];
                    }
                }

                return $out !== [['label' => '', 'id' => '']] ? $out : [['label' => '', 'id' => '']];
            }
        }
        $out = [];
        foreach ($labels as $lab) {
            $out[] = ['label' => (string) $lab, 'id' => ''];
        }

        return $out !== [] ? $out : [['label' => '', 'id' => '']];
    }

    /** @param array<string, mixed> $node */
    private static function isFlowLabelNode(array $node): bool
    {
        $role = strtolower((string) ($node['role'] ?? ''));
        if ($role === 'label') {
            return true;
        }
        $type = strtolower((string) ($node['type'] ?? ''));
        if (str_contains($type, 'label') || str_contains($type, 'text')) {
            // Plain text / label controls — not inputs that happen to contain "text".
            if (
                str_contains($type, 'textinput')
                || str_contains($type, 'textarea')
                || str_contains($type, 'html')
                || str_contains($type, 'richtext')
            ) {
                return false;
            }

            return str_contains($type, 'label') || $type === 'text@2.3.1' || str_starts_with($type, 'text@');
        }
        $name = (string) ($node['name'] ?? '');

        return (bool) preg_match('/(_Label|Label_|_label|Header)$/i', $name);
    }

    /**
     * Screen chrome order for flow/WET: topbar → sidebar → submit bar → body.
     * Studio parks topSubmit at a high Y with z-index overlay; without this it
     * sinks below the whole form and looks "missing".
     *
     * @param array<string, mixed> $node
     */
    private static function flowChromePriority(array $node): int
    {
        $name = (string) ($node['name'] ?? '');
        if (preg_match('/^Topbar/i', $name) === 1) {
            return 0;
        }
        if (preg_match('/^SidebarMenu/i', $name) === 1) {
            return 1;
        }
        if (preg_match('/^topSubmit/i', $name) === 1) {
            return 2;
        }
        if (preg_match('/HoldingContainer|ScrollableForm/i', $name) === 1) {
            return 4;
        }

        return 3;
    }

    private function sectionTopAnchorHtml(string $anchorName): string
    {
        $id = htmlspecialchars($anchorName, ENT_QUOTES);

        return '<a id="' . $id . '" name="' . $id . '" class="ctrl ctrl--anchor ctrl--section-anchor"'
            . ' data-control="' . $id . '" data-role="section-anchor" data-anchor="' . $id . '"'
            . ' data-scroll-to="' . $id . '"'
            . ' href="#' . $id . '" style="position:relative;display:block;width:0;height:0;'
            . 'overflow:hidden;pointer-events:none;scroll-margin-top:12px" aria-hidden="true" tabindex="-1"></a>';
    }

    private function renderPaMidAnchor(string $rawName, string $name, bool $absolute, int $x, int $y): string
    {
        $anchorCss = ['width:0', 'height:0', 'overflow:hidden', 'pointer-events:none'];
        if ($absolute) {
            array_unshift($anchorCss, 'position:absolute', 'left:' . $x . 'px', 'top:' . $y . 'px');
        } else {
            array_unshift($anchorCss, 'position:relative');
        }
        $anchorStyle = htmlspecialchars(implode(';', $anchorCss), ENT_QUOTES);

        // AnchorPark (and unknown 1×1 focus targets) keep a real id for SetFocus.
        // Named section anchors already have a top-of-section id — do not duplicate.
        if (SectionAnchors::isSectionAnchorName($rawName) && !SectionAnchors::isParkName($rawName)) {
            return '<span class="ctrl ctrl--anchor ctrl--pa-mid-anchor" data-control="'
                . $name . '" data-role="pa-anchor-mid" data-anchor="'
                . htmlspecialchars($rawName, ENT_QUOTES) . '" style="' . $anchorStyle
                . '" aria-hidden="true"></span>';
        }

        $id = htmlspecialchars($rawName, ENT_QUOTES);

        return '<a id="' . $id . '" name="' . $id . '" class="ctrl ctrl--anchor" data-control="'
            . $name . '" data-role="anchor" href="#' . $id . '" style="' . $anchorStyle
            . '" aria-hidden="true" tabindex="-1"></a>';
    }

    /**
     * @param array<string, mixed> $node
     * @return list<array{label:string,anchor:string}>
     */
    private function tabEntries(array $node): array
    {
        $items = $this->stripFx((string) (($node['properties']['Items'] ?? '') ?: ''));
        foreach (['colFormSectionsWithoutEmergency', 'colFormSections'] as $col) {
            if ($items === '' || str_contains($items, $col)) {
                $rows = $this->collections[$col] ?? [];
                $entries = [];
                foreach ($rows as $row) {
                    $lab = (string) ($row['Label'] ?? $row['Value'] ?? '');
                    if ($lab !== '' && strcasecmp($lab, 'Emergency') !== 0) {
                        $entries[] = [
                            'label' => $lab,
                            'anchor' => (string) ($row['AnchorName'] ?? ''),
                        ];
                    }
                }
                if ($entries !== []) {
                    return $entries;
                }
            }
        }

        $defaults = [
            ['label' => 'Destination', 'anchor' => 'Anchor1'],
            ['label' => 'Organization', 'anchor' => 'Anchor2'],
            ['label' => 'Visit type', 'anchor' => 'Anchor3'],
            ['label' => 'Classification', 'anchor' => 'Anchor4'],
            ['label' => 'Summary', 'anchor' => 'Anchor5'],
            ['label' => 'Dates', 'anchor' => 'Anchor6'],
            ['label' => 'Initiative', 'anchor' => 'Anchor7'],
            ['label' => 'Pertinence', 'anchor' => 'Anchor8'],
            ['label' => 'Security officer', 'anchor' => 'Anchor9'],
            ['label' => 'Remarks', 'anchor' => 'Anchor10'],
            ['label' => 'Sites', 'anchor' => 'AnchorSites'],
            ['label' => 'Visitors', 'anchor' => 'AnchorVisitors'],
        ];

        return $defaults;
    }

    /**
     * @param array<string, mixed> $node
     * @return list<string>
     */
    private function tabLabels(array $node): array
    {
        return array_map(static fn(array $e): string => $e['label'], $this->tabEntries($node));
    }

    /**
     * Omit from HTML entirely (never shown in the web preview).
     * Conditional Visible formulas must NOT be omitted — emit with is-hidden instead.
     *
     * @param array<string, mixed> $props
     */
    private function isOmitted(array $props): bool
    {
        $v = $this->stripFx((string) ($props['Visible'] ?? 'true'));
        if ($v === '' || strcasecmp($v, 'true') === 0) {
            return false;
        }
        if (strcasecmp($v, 'false') === 0) {
            return true;
        }
        // Trailing literal false after a commented-out If(...) .
        $withoutLineComments = preg_replace('/\/\/[^\n]*/', '', $v) ?? $v;
        if (trim($withoutLineComments) === 'false') {
            return true;
        }
        // Bare idle-false chrome that is never revealed in the web preview.
        // varTopMenu / varSideMenu / varShowConfirmNotifContainer stay in the DOM
        // (startsHidden) so Settings / notification confirm flyouts can open.
        foreach (['varShowLoadingContainer', 'varBugReport', 'fnIsAcknowledged', 'varShowConfirmDeleteContainer'] as $flag) {
            if (preg_match('/^' . $flag . '$/', $v) === 1) {
                return true;
            }
        }
        if (preg_match('/!IsBlank\s*\(\s*fn\w+/i', $v) === 1 && preg_match('/\bIf\s*\(/i', $v) !== 1) {
            return true;
        }

        return false;
    }

    /**
     * Emit with is-hidden so runtime applyVisibility can reveal the control.
     *
     * @param array<string, mixed> $props
     */
    private function startsHidden(array $props): bool
    {
        $v = $this->stripFx((string) ($props['Visible'] ?? 'true'));
        if ($v === '' || strcasecmp($v, 'true') === 0) {
            return false;
        }
        if ($this->isOmitted($props)) {
            return true;
        }
        // Idle-true negations (settings host is visible unless a bug report overlay is up).
        if (preg_match('/^!\s*(varBugReport|varShowLoadingContainer|varTopMenu|varSideMenu)$/', $v) === 1) {
            $flag = preg_replace('/^!\s*/', '', $v) ?? $v;
            // varBugReport / loading / menus start false → !flag is true → not hidden.
            return false;
        }
        // Settings panel / side menu / notif confirm start closed.
        if (preg_match('/^(varTopMenu|varSideMenu|varShowConfirmNotifContainer)$/', $v) === 1) {
            return true;
        }
        // Default admin pane is Request Lookup (1); other panes start hidden.
        if (preg_match('/varVisibleAdminScreen\s*=\s*(\d+)/', $v, $m) === 1) {
            return (int) $m[1] !== 1;
        }
        // Details screen OnVisible sets varDetailScreenSelect: 1 (PDF Overview).
        if (preg_match('/varDetailScreenSelect\s*=\s*(\d+)/', $v, $m) === 1) {
            return (int) $m[1] !== 1;
        }
        // App Admin Settings tabs: Help is the idle selection.
        if (preg_match('/AppSettingsGallery\.Selected\.Value\s*=\s*"([^"]+)"/', $v, $m) === 1) {
            return $m[1] !== 'Help';
        }
        // Preview defaults: Admin home card is reachable.
        if (preg_match('/varCurrentUser\.role\s*=\s*"Admin"/i', $v) === 1) {
            return false;
        }
        // Settings → Data debug buttons (fnDebugAllowed). Web preview defaults varEnv=dev.
        if (preg_match('/^fnDebugAllowed$/i', $v) === 1) {
            return false;
        }
        // User Roles: preview seeds varTempUser with one row → AllItemsCount > 0.
        // Gallery Visible = If(count = 0, false, true); empty HtmlText is the inverse.
        if (preg_match('/UserGallery\.AllItemsCount\s*=\s*0/i', $v) === 1) {
            if (preg_match('/If\s*\(\s*UserGallery\.AllItemsCount\s*=\s*0\s*,\s*false/i', $v) === 1) {
                return false;
            }
            if (preg_match('/If\s*\(\s*UserGallery\.AllItemsCount\s*=\s*0\s*,\s*true/i', $v) === 1) {
                return true;
            }
        }
        // Details Acknowledgment: idle Approval Tracking is empty → show NoApprovals,
        // hide ApprovalGallery (!IsEmpty → hidden).
        if (preg_match('/IsEmpty\s*\(\s*Filter\s*\(\s*\'CDLS \(L\) Approval Tracking\'/i', $v) === 1) {
            if (preg_match('/^!\s*IsEmpty/i', $v) === 1) {
                return true;
            }

            return false;
        }
        // Any other formula is treated as idle-false until the runtime evaluates it.
        return true;
    }

    /** @deprecated Use isOmitted(); kept for callers/tests that still name it isHidden. */
    private function isHidden(array $props): bool
    {
        return $this->isOmitted($props);
    }

    /** @param array<string, mixed> $node */
    private function visibleText(array $node): string
    {
        $labels = is_array($node['labels'] ?? null) ? $node['labels'] : [];
        foreach (['Text', 'AccessibleLabel'] as $key) {
            $t = trim((string) ($labels[$key] ?? ''));
            if ($t !== '' && !$this->looksLikeFormula($t) && !str_contains($t, ' & ')) {
                return $t;
            }
        }
        foreach (['Text', 'AccessibleLabel'] as $prop) {
            $raw = (string) (($node['properties'][$prop] ?? null) ?: '');
            $preview = $this->previewUserText($raw);
            if ($preview !== '') {
                return $preview;
            }
            $clean = $this->literalString($raw);
            if ($clean !== '') {
                return $clean;
            }
        }

        return '';
    }

    /** Soft-evaluate common User()/greeting concatenations for static HTML. */
    private function previewUserText(string $raw): string
    {
        $clean = $this->stripFx($raw);
        if ($clean === '') {
            return '';
        }
        if (preg_match('/^"Signed in as "\s*&\s*User\(\)\.Email$/i', $clean) === 1) {
            return 'Signed in as preview@local';
        }
        if (preg_match('/Hour\s*\(\s*Now\s*\(\s*\)\s*\)/i', $clean) === 1) {
            $h = (int) date('G');
            $greet = $h < 12 ? 'Good Morning' : ($h < 17 ? 'Good Afternoon' : 'Good Evening');

            return $greet . ', Preview';
        }
        // "literal" & User().Email / FullName
        if (preg_match('/^"((?:\\\\.|[^"])*)"\s*&\s*User\(\)\.(Email|FullName)/i', $clean, $m)) {
            $user = strcasecmp($m[2], 'Email') === 0 ? 'preview@local' : 'Preview User';

            return stripcslashes($m[1]) . $user;
        }
        // Gallery item titles: "Site " & ThisItem.ID & ": " & ...
        if (preg_match('/^"((?:\\\\.|[^"])*)"\s*&\s*ThisItem\.ID\b/i', $clean, $m)) {
            return stripcslashes($m[1]) . '1';
        }
        if (preg_match('/^"((?:\\\\.|[^"])*)"\s*&\s*ThisItem\./i', $clean, $m)) {
            return stripcslashes($m[1]);
        }
        // Details header: "ID: " & varRequestID / "Email: " & varCurrentPackage...
        if (preg_match('/^"((?:\\\\.|[^"])*)"\s*&\s*(varRequestID|varCurrentPackage)\b/i', $clean, $m)) {
            return stripcslashes($m[1]);
        }

        return '';
    }

    /** @param array<string, mixed> $node */
    private function placeholder(array $node): string
    {
        $labels = is_array($node['labels'] ?? null) ? $node['labels'] : [];
        $t = trim((string) ($labels['HintText'] ?? $labels['Placeholder'] ?? ''));
        if ($t !== '' && !$this->looksLikeFormula($t)) {
            return $t;
        }
        $props = is_array($node['properties'] ?? null) ? $node['properties'] : [];
        foreach (['Placeholder', 'HintText', 'InputTextPlaceholder'] as $key) {
            $hint = $this->literalString((string) (($props[$key] ?? null) ?: ''));
            if ($hint !== '') {
                return $hint;
            }
        }

        return 'Enter text';
    }

    /** @param array<string, mixed> $node */
    private function htmlText(array $node): string
    {
        $raw = (string) (($node['properties']['HtmlText'] ?? $node['labels']['HtmlText'] ?? '') ?: '');
        if (preg_match('/Jump to (Sites|Visitors)/i', $raw, $m)) {
            $target = strcasecmp($m[1], 'Sites') === 0 ? 'AnchorSites' : 'AnchorVisitors';

            return '<a class="ctrl-link" href="#' . htmlspecialchars($target, ENT_QUOTES)
                . '" data-scroll-to="' . htmlspecialchars($target, ENT_QUOTES) . '">Jump to '
                . htmlspecialchars($m[1], ENT_QUOTES) . '</a>';
        }

        $clean = $this->stripFx($raw);
        // Named HTML builders from app Formulas (Request Summary / PDF Overview).
        if (preg_match('/^(fnCreateSummaryHTML|fnCreatePDF)\s*$/i', $clean, $fn) === 1) {
            $built = $this->expandNamedHtmlFormula($fn[1]);
            if ($built !== '') {
                return $built;
            }
        }
        // Prefer the first complete quoted HTML literal (Settings/Admin instruction blurb).
        $lit = '';
        if (preg_match('/^"((?:\\\\.|[^"])*)"/s', $clean, $qm) === 1) {
            $lit = stripcslashes($qm[1]);
        } elseif (preg_match("/^'((?:\\\\.|[^'])*)'/s", $clean, $qm) === 1) {
            $lit = stripcslashes($qm[1]);
        } elseif (
            (str_starts_with($clean, '"') && str_ends_with($clean, '"') && substr_count($clean, '"') === 2)
            || (str_starts_with($clean, "'") && str_ends_with($clean, "'") && substr_count($clean, "'") === 2)
        ) {
            $lit = substr($clean, 1, -1);
        } elseif (!$this->looksLikeFormula($clean)) {
            $lit = $this->literalString($raw);
        }
        if ($lit !== '') {
            $lit = preg_replace('/"\s*&\s*gblTheme\.\w+Css\s*&\s*"/', '#1d4ed8', $lit) ?? $lit;
            // Help tab: "...fields available are:<br/>" & Concat(fnEmailTokens, ...) & "</p>"
            if (preg_match('/&\s*Concat\s*\(\s*fnEmailTokens\s*,/i', $clean) === 1) {
                $lines = [];
                foreach ($this->collections['fnEmailTokens'] ?? [] as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $key = (string) ($row['Key'] ?? '');
                    $label = (string) ($row['Label'] ?? '');
                    if ($key === '') {
                        continue;
                    }
                    $lines[] = '{' . htmlspecialchars($key, ENT_QUOTES) . '} — '
                        . htmlspecialchars($label, ENT_QUOTES);
                }
                $lit .= implode('<br/>', $lines);
                // Trailing literal after Concat(...), allowing nested parens in the Concat args.
                if (preg_match('/Concat\s*\(/i', $clean, $cm, PREG_OFFSET_CAPTURE) === 1) {
                    $start = (int) $cm[0][1] + strlen($cm[0][0]) - 1; // at '('
                    $depth = 0;
                    $end = -1;
                    $cleanLen = strlen($clean);
                    for ($i = $start; $i < $cleanLen; $i++) {
                        $ch = $clean[$i];
                        if ($ch === '(') {
                            $depth++;
                        } elseif ($ch === ')') {
                            $depth--;
                            if ($depth === 0) {
                                $end = $i;
                                break;
                            }
                        }
                    }
                    if ($end >= 0 && preg_match('/^\s*&\s*"((?:\\\\.|[^"])*)"/s', substr($clean, $end + 1), $tail) === 1) {
                        $lit .= stripcslashes($tail[1]);
                    } elseif (!str_ends_with(rtrim($lit), '</p>')) {
                        $lit .= '</p>';
                    }
                } elseif (!str_ends_with(rtrim($lit), '</p>')) {
                    $lit .= '</p>';
                }
            }
            if ($this->isSafeHtmlFragment($lit)) {
                return $lit;
            }
        }

        return htmlspecialchars($this->visibleText($node), ENT_QUOTES);
    }

    /**
     * Expand fnCreateSummaryHTML / fnCreatePDF from app Formulas into static HTML chrome.
     * Dynamic field concatenations are blanked; theme CSS tokens are substituted.
     */
    private function expandNamedHtmlFormula(string $name): string
    {
        $formulas = $this->formulasBlob;
        if ($formulas === '') {
            return '';
        }
        $needle = $name . ' =';
        $pos = strrpos($formulas, $needle);
        if ($pos === false) {
            $needle = $name . '=';
            $pos = strrpos($formulas, $needle);
        }
        if ($pos === false) {
            return '';
        }
        $rest = substr($formulas, $pos + strlen($needle));
        // Stop at the next top-level assignment (different name), else take to EOF.
        if (preg_match('/^(.*?)(?:\n(?!' . preg_quote($name, '/') . ')[A-Za-z_][\w]*\s*=)/s', $rest, $m) === 1) {
            $body = $m[1];
        } else {
            $body = $rest;
        }
        $map = [
            'cText' => '#0f172a',
            'cTextMuted' => '#64748b',
            'cSurface' => '#ffffff',
            'cSurfaceMuted' => '#f1f5f9',
            'cSectionHeadBg' => '#bacae2',
            'cBorder' => '#cbd5e1',
            'cLink' => '#2563eb',
            'cPage' => '#f8fafc',
            'cSurfaceAlt' => '#bacae2',
            'cBorderStrong' => '#0f172a',
            'cHeaderBg' => '#b89645',
            'cHeaderText' => '#ffffff',
        ];
        foreach ($map as $token => $color) {
            $body = preg_replace('/"\s*&\s*' . preg_quote($token, '/') . '\s*&\s*"/', $color, $body) ?? $body;
        }
        // Drop dynamic concatenations between string literals.
        $body = preg_replace('/"\s*&\s*[^"\n]+?\s*&\s*"/', '', $body) ?? $body;
        $parts = [];
        if (preg_match_all('/"((?:\\\\.|[^"])*)"/', $body, $q) > 0) {
            foreach ($q[1] as $p) {
                $parts[] = stripcslashes($p);
            }
        }
        $html = trim(implode('', $parts));
        if ($html === '') {
            return '';
        }
        // Formula returns a <tr> fragment — wrap for standalone viewing.
        if (str_starts_with($html, '<tr')) {
            $html = '<table class="vcr-html-formula" width="100%" cellpadding="0" cellspacing="0" border="0">'
                . $html . '</table>';
        }
        if (!$this->isSafeHtmlFragment($html)) {
            return '';
        }

        return $html;
    }

    private function isSafeHtmlFragment(string $html): bool
    {
        if ($html === '') {
            return false;
        }
        // Truncated open tags or attribute quotes will poison the rest of the document.
        if (preg_match('/<[a-zA-Z][^>]*$/', $html) === 1) {
            return false;
        }
        $inTag = false;
        $quote = null;
        $len = strlen($html);
        for ($i = 0; $i < $len; $i++) {
            $ch = $html[$i];
            if ($quote !== null) {
                if ($ch === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($inTag) {
                if ($ch === '"' || $ch === "'") {
                    $quote = $ch;
                } elseif ($ch === '>') {
                    $inTag = false;
                }
                continue;
            }
            if ($ch === '<') {
                $inTag = true;
            }
        }

        return !$inTag && $quote === null;
    }

    private function literalString(string $raw): string
    {
        $clean = $this->stripFx($raw);
        if ($clean === '') {
            return '';
        }
        if (preg_match('/^If\s*\(\s*(fnIsEmerStep|fnIsAcknowledged|var\w+|false)\s*,\s*"((?:\\\\.|[^"])*)"\s*,\s*"((?:\\\\.|[^"])*)"\s*\)$/is', $clean, $m)) {
            return stripcslashes($m[3]);
        }
        // Default falsey control values (VIP.Value, Checkbox.Value, etc.) → else branch.
        if (preg_match('/^If\s*\(\s*[\w.]+\s*,\s*"((?:\\\\.|[^"])*)"\s*,\s*"((?:\\\\.|[^"])*)"\s*\)$/is', $clean, $m)) {
            return stripcslashes($m[2]);
        }
        if ($this->looksLikeFormula($clean)) {
            if (preg_match('/"((?:\\\\.|[^"]){2,})"/', $clean, $m) && !$this->looksLikeFormula($m[1])) {
                return stripcslashes($m[1]);
            }

            return '';
        }
        if (
            (str_starts_with($clean, '"') && str_ends_with($clean, '"'))
            || (str_starts_with($clean, "'") && str_ends_with($clean, "'"))
        ) {
            return substr($clean, 1, -1);
        }

        return $clean;
    }

    /** @param array<string, mixed> $node */
    private function defaultInputValue(array $node): string
    {
        $raw = $this->stripFx((string) (($node['properties']['Default'] ?? '') ?: ''));
        if ($raw === '') {
            return '';
        }
        if (is_numeric($raw)) {
            return (string) (0 + $raw);
        }
        if (preg_match('/^Max\s*\(\s*1\b/i', $raw)) {
            return '1';
        }
        if (preg_match('/^Coalesce\s*\(\s*[^,]+,\s*"((?:\\\\.|[^"])*)"\s*\)/is', $raw, $m)) {
            return stripcslashes($m[1]);
        }

        return '';
    }

    private function looksLikeFormula(string $t): bool
    {
        $t = trim($t);

        return (bool) preg_match('/\b(If|Switch|LookUp|Filter|Navigate|Set|User|Coalesce|ThisItem|Parent|Self)\s*[\.(]/', $t)
            || (bool) preg_match('/^(fn|var|gbl)[A-Z]\w*$/', $t)
            || str_contains($t, '/*')
            || str_starts_with($t, '//');
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

    private function numericProp(mixed $raw): ?int
    {
        if ($raw === null) {
            return null;
        }
        $v = $this->stripFx((string) $raw);

        return is_numeric($v) ? (int) round((float) $v) : null;
    }

    private function prettyName(string $name): string
    {
        $name = preg_replace('/_+\d+$/', '', $name) ?? $name;
        $name = str_replace('_', ' ', $name);

        return trim($name);
    }
}
