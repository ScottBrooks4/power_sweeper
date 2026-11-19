<?php

declare(strict_types=1);

namespace PowerSweeper\Hops;

use PowerSweeper\ControlDocument;
use PowerSweeper\ControlNode;
use PowerSweeper\HopOptions;
use PowerSweeper\Report;

/**
 * Fill AccessibleLabel for people who cannot rely on sight alone.
 *
 * Prefer spoken purpose (visible caption, tooltip, icon meaning, destination)
 * over internal Studio names like Button1 / Icon5.
 */
final class AccessibilityLabelsHop implements HopInterface
{
    /** @var array<string, ControlNode> */
    private array $byPath = [];

    /** @var array<string, list<ControlNode>> */
    private array $byParent = [];

    public static function id(): string
    {
        return 'accessibility_labels';
    }

    public static function label(): string
    {
        return 'Accessibility labels';
    }

    public static function description(): string
    {
        return 'Fill missing AccessibleLabel with user-facing purpose: nearby captions, tooltips, icon meaning (e.g. right arrow), and action context (e.g. to select a form) — not internal control names. Checkboxes/toggles become “checkbox for …”.';
    }

    public function apply(array $documents, Report $report, array $options = []): void
    {
        $force = HopOptions::force($options);

        foreach ($documents as $doc) {
            $this->indexDocument($doc);

            foreach ($doc->controls() as $control) {
                if (!$control->isInteractive()) {
                    continue;
                }

                $existing = $control->getProperty('AccessibleLabel');
                if (
                    !$force
                    && $existing !== null
                    && !$this->isBlank($existing)
                    && !$this->isBrokenLabel($existing, $control)
                ) {
                    continue;
                }

                $to = $this->resolveAccessibleLabel($control);
                if ($to === null || $to === '') {
                    continue;
                }

                $before = $existing ?? '(unset)';
                $control->setProperty('AccessibleLabel', $to);
                $report->add(self::id(), $control->path, 'AccessibleLabel', $before, $to);
            }
        }

        $this->byPath = [];
        $this->byParent = [];
    }

    private function indexDocument(ControlDocument $doc): void
    {
        $this->byPath = [];
        $this->byParent = [];
        foreach ($doc->controls() as $control) {
            $this->byPath[$control->path] = $control;
            $parent = $this->parentPath($control->path);
            if ($parent === null) {
                continue;
            }
            $this->byParent[$parent] ??= [];
            $this->byParent[$parent][] = $control;
        }
    }

    /**
     * Build the AccessibleLabel assignment (yaml includes leading = when needed).
     */
    private function resolveAccessibleLabel(ControlNode $control): ?string
    {
        // 1) Own visible / hint text — already what a sighted user reads.
        $text = $control->getProperty('Text');
        if ($text !== null && !$this->isBlank($text)) {
            if ($this->isDynamicExpression($text)) {
                return $this->rolePrefixedRef($control, 'Self.Text');
            }

            return $this->spokenLiteral($control, $this->cleanCaption($this->unwrap($text)));
        }

        foreach (['Tooltip', 'HintText'] as $prop) {
            $val = $control->getProperty($prop);
            if ($val === null || $this->isBlank($val)) {
                continue;
            }
            if ($this->isDynamicExpression($val)) {
                return $this->rolePrefixedRef($control, 'Self.' . $prop);
            }

            return $this->spokenLiteral($control, $this->cleanCaption($this->unwrap($val)));
        }

        // 2) Nested label children (buttons wrapping a Label, etc.)
        $fromChild = $this->labelFromChildren($control);
        if ($fromChild !== null) {
            return $fromChild;
        }

        // 3) Neighboring captions (siblings / parent peers) — critical for inputs & icons
        $fromNeighbor = $this->labelFromNeighbors($control);
        if ($fromNeighbor !== null) {
            return $fromNeighbor;
        }

        // 4) Purpose from icon meaning + action (Navigate) + meaningful name stems.
        // Never fall back to "Button 1" / "Icon 5" — that is maker jargon, not user speech.
        return $this->purposeFromContext($control);
    }

    private function labelFromChildren(ControlNode $control): ?string
    {
        foreach ($control->children as $child) {
            if (!$this->isLabelLike($child)) {
                continue;
            }
            $childText = $child->getProperty('Text');
            if ($childText === null || $this->isBlank($childText)) {
                continue;
            }
            if ($this->isDynamicExpression($childText)) {
                if ($this->isSafeControlRef($child->name)) {
                    return $this->rolePrefixedRef($control, $child->name . '.Text');
                }
                $clean = $this->unwrap($childText);
                if ($clean !== '' && !$this->isDynamicExpression('=' . $clean)) {
                    return $this->spokenLiteral($control, $this->cleanCaption($clean));
                }
                continue;
            }

            return $this->spokenLiteral($control, $this->cleanCaption($this->unwrap($childText)));
        }

        return null;
    }

    private function labelFromNeighbors(ControlNode $control): ?string
    {
        $candidates = $this->neighborLabelCandidates($control);
        if ($candidates === []) {
            return null;
        }

        usort($candidates, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);
        $best = $candidates[0];
        // Require a clear above/left/right or name-pair signal (not vague proximity).
        $minScore = 40;
        // Icons/buttons that already expose Icon= / Navigate purpose should only
        // take a neighbor when it is a tight caption (right/name), not a distant form label.
        if ($this->iconDescription($control) !== null || $this->actionPhrase($control) !== null) {
            $minScore = 70;
        }
        if ($best['score'] < $minScore) {
            return null;
        }

        /** @var ControlNode $label */
        $label = $best['control'];
        $text = $label->getProperty('Text');
        if ($text === null || $this->isBlank($text)) {
            return null;
        }

        if ($this->isDynamicExpression($text) && $this->isSafeControlRef($label->name)) {
            return $this->rolePrefixedRef($control, $label->name . '.Text');
        }
        if ($this->isDynamicExpression($text)) {
            return null;
        }

        return $this->spokenLiteral($control, $this->cleanCaption($this->unwrap($text)));
    }

    /**
     * Last-resort purpose when no visible caption exists.
     */
    private function purposeFromContext(ControlNode $control): ?string
    {
        $icon = $this->iconDescription($control);
        $action = $this->actionPhrase($control);
        $fromName = $this->purposeFromControlName($control);
        $role = $this->rolePhrase($control);

        if ($icon !== null && $action !== null) {
            return $this->quotedLiteral($control, $icon . ' ' . $action);
        }
        if ($icon !== null && $fromName !== null) {
            // Avoid "Save to save only" when the name restates the icon.
            if (
                strcasecmp($icon, $fromName) === 0
                || str_contains(mb_strtolower($fromName), mb_strtolower($icon))
                || str_contains(mb_strtolower($icon), mb_strtolower($fromName))
            ) {
                return $this->quotedLiteral($control, $icon);
            }

            return $this->quotedLiteral($control, $icon . ' to ' . $this->sentenceCase($fromName));
        }
        if ($fromName !== null) {
            return $this->spokenLiteral($control, $fromName);
        }
        if ($icon !== null && $action === null) {
            return $this->quotedLiteral($control, $icon);
        }
        if ($action !== null) {
            // "to Request Form" → "Go to Request Form"
            $spoken = str_starts_with($action, 'to ')
                ? 'Go ' . $action
                : $action;

            return $this->quotedLiteral($control, $spoken);
        }

        // Weak but still user-facing role, never "Button 1".
        return $role !== '' ? $this->quotedLiteral($control, ucfirst($role)) : null;
    }

    /**
     * Wrap checkbox/toggle purpose; leave buttons/inputs as the caption itself.
     */
    private function spokenLiteral(ControlNode $control, string $purpose): ?string
    {
        $purpose = $this->cleanCaption($purpose);
        if ($purpose === '') {
            return null;
        }
        if ($this->needsRolePrefix($control)) {
            $purpose = $this->forPhrase($this->rolePhrase($control), $purpose);
        }

        return $this->quotedLiteral($control, $purpose);
    }

    private function rolePrefixedRef(ControlNode $control, string $expr): string
    {
        if (!$this->needsRolePrefix($control)) {
            return $this->formulaRef($control, $expr);
        }
        $role = $this->rolePhrase($control);
        $prefix = '"' . str_replace('"', '""', $role . ' for ') . '"';

        return $this->formulaRef($control, $prefix . ' & ' . $expr);
    }

    private function needsRolePrefix(ControlNode $control): bool
    {
        $t = strtolower($control->type);

        return str_contains($t, 'checkbox')
            || str_contains($t, 'toggle')
            || str_contains($t, 'switch');
    }

    private function rolePhrase(ControlNode $control): string
    {
        $t = strtolower($control->type);
        if (str_contains($t, 'checkbox')) {
            return 'checkbox';
        }
        if (str_contains($t, 'toggle') || str_contains($t, 'switch')) {
            return 'toggle';
        }
        if (str_contains($t, 'radio')) {
            return 'radio group';
        }
        if (str_contains($t, 'dropdown') || str_contains($t, 'combobox')) {
            return 'dropdown';
        }
        if (str_contains($t, 'datepicker')) {
            return 'date picker';
        }
        if (str_contains($t, 'slider')) {
            return 'slider';
        }
        if (str_contains($t, 'gallery')) {
            return 'gallery';
        }
        if (str_contains($t, 'icon')) {
            return 'icon';
        }
        if (str_contains($t, 'image') && !str_contains($t, 'input')) {
            return 'image';
        }
        if (str_contains($t, 'button') || str_contains($t, 'link')) {
            return 'button';
        }
        if (str_contains($t, 'textinput') || $t === 'text' || $t === 'textarea') {
            return 'text input';
        }

        return 'control';
    }

    private function forPhrase(string $role, string $purpose): string
    {
        $purpose = $this->cleanCaption($purpose);
        if ($purpose === '') {
            return $role;
        }
        if ($role !== '' && preg_match('/^\s*' . preg_quote($role, '/') . '\b/i', $purpose) === 1) {
            return $purpose;
        }
        if ($role === '') {
            return $purpose;
        }

        return $role . ' for ' . $this->sentenceCase($purpose);
    }

    private function cleanCaption(string $text): string
    {
        $text = trim($text);
        $text = rtrim($text, " \t:");
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);

        return $text;
    }

    private function sentenceCase(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return $text;
        }
        $parts = preg_split('/\s+/', $text) ?: [];
        $out = [];
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            // Keep short ALL-CAPS tokens (PDF, ID, URL).
            if (preg_match('/^[A-Z]{2,5}$/', $part) === 1) {
                $out[] = $part;
                continue;
            }
            $out[] = mb_strtolower($part);
        }

        return implode(' ', $out);
    }

    private function iconDescription(ControlNode $control): ?string
    {
        $t = strtolower($control->type);
        if (!str_contains($t, 'icon')) {
            // Some buttons use Icon= without being Icon controls.
            $raw = $control->getProperty('Icon');
            if ($raw === null || $this->isBlank($raw)) {
                return null;
            }
        } else {
            $raw = $control->getProperty('Icon');
            if ($raw === null || $this->isBlank($raw)) {
                return null;
            }
        }

        $token = $this->unwrap((string) $raw);
        if (!preg_match('/\bIcon\.([A-Za-z][A-Za-z0-9]*)\b/', $token, $m)) {
            return null;
        }

        return $this->speakIconName($m[1]);
    }

    private function speakIconName(string $iconName): string
    {
        $map = [
            'ChevronRight' => 'Right arrow',
            'ChevronLeft' => 'Left arrow',
            'ChevronUp' => 'Up arrow',
            'ChevronDown' => 'Down arrow',
            'ArrowRight' => 'Right arrow',
            'ArrowLeft' => 'Left arrow',
            'ArrowUp' => 'Up arrow',
            'ArrowDown' => 'Down arrow',
            'NextArrow' => 'Next',
            'BackArrow' => 'Back',
            'Forward' => 'Forward',
            'Back' => 'Back',
            'Add' => 'Add',
            'AddDocument' => 'Add document',
            'Save' => 'Save',
            'Edit' => 'Edit',
            'EditDocument' => 'Edit document',
            'Trash' => 'Delete',
            'Delete' => 'Delete',
            'Cancel' => 'Cancel',
            'CancelBadge' => 'Cancel',
            'Check' => 'Check',
            'CheckBadge' => 'Check',
            'Home' => 'Home',
            'Search' => 'Search',
            'Settings' => 'Settings',
            'SettingsOutline' => 'Settings',
            'Info' => 'Information',
            'Warning' => 'Warning',
            'Error' => 'Error',
            'Mail' => 'Mail',
            'Message' => 'Message',
            'Phone' => 'Phone',
            'Calendar' => 'Calendar',
            'Clock' => 'Clock',
            'Person' => 'Person',
            'People' => 'People',
            'Filter' => 'Filter',
            'Print' => 'Print',
            'Download' => 'Download',
            'Upload' => 'Upload',
            'Refresh' => 'Refresh',
            'Reload' => 'Reload',
            'More' => 'More options',
            'MoreVertical' => 'More options',
            'Hamburger' => 'Menu',
            'List' => 'List',
            'View' => 'View',
            'Hide' => 'Hide',
            'Lock' => 'Lock',
            'Unlock' => 'Unlock',
            'Copy' => 'Copy',
            'Cut' => 'Cut',
            'Paste' => 'Paste',
            'Send' => 'Send',
            'Share' => 'Share',
            'Star' => 'Star',
            'Favorite' => 'Favorite',
            'Flag' => 'Flag',
            'Pin' => 'Pin',
            'Location' => 'Location',
            'Document' => 'Document',
            'Folder' => 'Folder',
            'Attach' => 'Attach',
            'Camera' => 'Camera',
            'Photo' => 'Photo',
            'Play' => 'Play',
            'Pause' => 'Pause',
            'Stop' => 'Stop',
            'Items' => 'Items',
            'DetailList' => 'Details',
            'DockLeft' => 'Previous',
            'DockRight' => 'Next',
        ];
        if (isset($map[$iconName])) {
            return $map[$iconName];
        }

        $words = preg_replace('/([a-z])([A-Z])/', '$1 $2', $iconName) ?? $iconName;
        $words = trim(preg_replace('/\s+/', ' ', $words) ?? $words);

        return $words !== '' ? $words : 'Icon';
    }

    private function actionPhrase(ControlNode $control): ?string
    {
        foreach (['OnSelect', 'OnChange', 'OnCheck', 'OnUncheck'] as $prop) {
            $raw = $control->getProperty($prop);
            if ($raw === null || $this->isBlank($raw)) {
                continue;
            }
            $code = $this->unwrap($raw);
            if (preg_match(
                '/\bNavigate\s*\(\s*(?:\'([^\']+)\'|"([^"]+)"|([A-Za-z_][\w]*))/',
                $code,
                $m
            ) === 1) {
                $screen = $m[1] !== '' ? $m[1] : ($m[2] !== '' ? $m[2] : ($m[3] ?? ''));
                $screen = $this->humanizeIdentifier($screen);
                if ($screen === '') {
                    continue;
                }

                return 'to ' . $screen;
            }
            if (preg_match('/\bSelect\s*\(\s*Parent\s*\)/i', $code) === 1) {
                return 'to select item';
            }
            if (preg_match('/\bRemove\s*\(/i', $code) === 1) {
                return 'to remove';
            }
            if (preg_match('/\bEditForm\s*\(/i', $code) === 1) {
                return 'to edit form';
            }
            if (preg_match('/\bNewForm\s*\(/i', $code) === 1) {
                return 'to create new';
            }
            if (preg_match('/\bSubmitForm\s*\(/i', $code) === 1) {
                return 'to submit form';
            }
            if (preg_match('/\bResetForm\s*\(/i', $code) === 1) {
                return 'to reset form';
            }
            if (preg_match('/\bViewForm\s*\(/i', $code) === 1) {
                return 'to view form';
            }
        }

        return null;
    }

    private function purposeFromControlName(ControlNode $control): ?string
    {
        $name = $control->name;
        if ($name === '' || $this->isStudioGenericName($name)) {
            return null;
        }

        $stem = preg_replace(
            '/^(lbl|txt|txt_|inp|input|cmb|cbo|ddl|drp|btn|img|ico|chk|tgl)+/i',
            '',
            $name
        ) ?? $name;
        $stem = preg_replace(
            '/(Label|Lbl|TextInput|TextBox|Input|Field|ComboBox|Combo|Dropdown|DropDown|DatePicker|Picker|CheckBox|Checkbox|Check|Toggle|Slider|Gallery|Button|Btn|Icon|Image|Radio)$/i',
            '',
            $stem
        ) ?? $stem;
        $stem = preg_replace('/\d+$/', '', $stem) ?? $stem;
        $stem = trim($stem, "_- \t");
        if ($stem === '' || $this->isStudioGenericName($stem) || strlen($stem) < 3) {
            return null;
        }
        // Reject stems that are still just a type word.
        if (preg_match(
            '/^(Button|Icon|Image|Label|Input|Checkbox|Toggle|Dropdown|Gallery|Container|Control)$/i',
            $stem
        ) === 1) {
            return null;
        }

        $words = $this->humanizeIdentifier($stem);
        if ($words === '' || mb_strlen($words) < 3) {
            return null;
        }

        return $words;
    }

    private function humanizeIdentifier(string $name): string
    {
        $name = str_replace(['_', '-'], ' ', $name);
        $name = preg_replace('/([a-z])([A-Z])/', '$1 $2', $name) ?? $name;
        $name = preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1 $2', $name) ?? $name;
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? $name);
        if ($name === '') {
            return '';
        }
        // Title-ish words for screen names / stems, then sentenceCase applied by callers when needed.
        $parts = explode(' ', $name);
        $parts = array_map(static function (string $p): string {
            if ($p === '') {
                return $p;
            }
            if (preg_match('/^[A-Z]{2,5}$/', $p) === 1) {
                return $p;
            }

            return mb_strtoupper(mb_substr($p, 0, 1)) . mb_strtolower(mb_substr($p, 1));
        }, $parts);

        return trim(implode(' ', $parts));
    }

    private function isStudioGenericName(string $name): bool
    {
        return preg_match(
            '/^(Button|Icon|Image|Label|TextInput|Text|TextBox|Checkbox|CheckBox|Toggle|Dropdown|ComboBox|Gallery|Container|Group|Rectangle|Circle|HtmlText|HtmlViewer|DatePicker|Slider|Radio|ModernButton|ButtonCanvas)\d*(_\d+)?$/i',
            $name
        ) === 1
            || preg_match('/^(btn|ico|img|chk|tgl|lbl|txt)\d+(_\d+)?$/i', $name) === 1;
    }

    /**
     * @return list<array{control: ControlNode, score: int}>
     */
    private function neighborLabelCandidates(ControlNode $control): array
    {
        $parent = $this->parentPath($control->path);
        $pool = [];
        if ($parent !== null) {
            foreach ($this->byParent[$parent] ?? [] as $sib) {
                if ($sib->path === $control->path) {
                    continue;
                }
                $pool[] = $sib;
            }
            foreach ($this->byParent[$parent] ?? [] as $sib) {
                if ($sib->path === $control->path || !$sib->isContainer()) {
                    continue;
                }
                foreach ($sib->children as $nibling) {
                    $pool[] = $nibling;
                }
            }
        }

        $grand = $parent !== null ? $this->parentPath($parent) : null;
        if ($grand !== null) {
            foreach ($this->byParent[$grand] ?? [] as $uncle) {
                if ($uncle->path === $parent) {
                    continue;
                }
                $pool[] = $uncle;
                foreach ($uncle->children as $cousin) {
                    $pool[] = $cousin;
                }
            }
        }

        $seen = [];
        $out = [];
        foreach ($pool as $node) {
            if (isset($seen[$node->path]) || !$this->isLabelLike($node)) {
                continue;
            }
            $seen[$node->path] = true;
            $text = $node->getProperty('Text');
            if ($text === null || $this->isBlank($text)) {
                continue;
            }
            $score = $this->scoreNeighborLabel($control, $node);
            if ($score <= 0) {
                continue;
            }
            $out[] = ['control' => $node, 'score' => $score];
        }

        return $out;
    }

    private function scoreNeighborLabel(ControlNode $target, ControlNode $label): int
    {
        $score = 0;

        $nameScore = $this->namePairScore($target->name, $label->name);
        $score += $nameScore;

        $tx = $this->layoutNumber($target, 'X');
        $ty = $this->layoutNumber($target, 'Y');
        $tw = $this->layoutNumber($target, 'Width') ?? 120.0;
        $th = $this->layoutNumber($target, 'Height') ?? 40.0;
        $lx = $this->layoutNumber($label, 'X');
        $ly = $this->layoutNumber($label, 'Y');
        $lw = $this->layoutNumber($label, 'Width') ?? 120.0;
        $lh = $this->layoutNumber($label, 'Height') ?? 40.0;

        if ($tx !== null && $ty !== null && $lx !== null && $ly !== null) {
            $targetRight = $tx + $tw;
            $targetBottom = $ty + $th;
            $labelRight = $lx + $lw;
            $labelBottom = $ly + $lh;

            $overlapX = max(0.0, min($targetRight, $labelRight) - max($tx, $lx));
            $overlapY = max(0.0, min($targetBottom, $labelBottom) - max($ty, $ly));

            $t = strtolower($target->type);
            $isFormField = str_contains($t, 'input')
                || str_contains($t, 'dropdown')
                || str_contains($t, 'combobox')
                || str_contains($t, 'datepicker')
                || str_contains($t, 'slider')
                || $t === 'text'
                || $t === 'textarea';
            $isIconOrImage = str_contains($t, 'icon') || (str_contains($t, 'image') && !str_contains($t, 'input'));
            $isCheckOrToggle = str_contains($t, 'checkbox')
                || str_contains($t, 'toggle')
                || str_contains($t, 'switch');

            // Label above input (classic form). Skip icons/buttons — a wide caption
            // must not become every control's AccessibleLabel.
            if (
                $isFormField
                && $overlapX >= min($tw, $lw) * 0.35
                && $labelBottom <= $ty + 12
                && $ty - $labelBottom <= 80
            ) {
                $score += 55;
                $gap = $ty - $labelBottom;
                $score += (int) max(0, 25 - $gap / 2);
            }

            if ($overlapY >= min($th, $lh) * 0.35 && $labelRight <= $tx + 12 && $tx - $labelRight <= 160) {
                $score += 50;
                $gap = $tx - $labelRight;
                $score += (int) max(0, 20 - $gap / 4);
            }

            // Caption to the right (checkbox row / icon+text / button chip).
            if (
                ($isIconOrImage || $isCheckOrToggle || str_contains($t, 'button'))
                && $overlapY >= min($th, $lh) * 0.35
                && $lx >= $targetRight - 12
                && $lx - $targetRight <= 200
            ) {
                $score += $isCheckOrToggle ? 60 : 48;
                $gap = $lx - $targetRight;
                $score += (int) max(0, 18 - $gap / 4);
            }

            // Tiny proximity nudge only when a directional/name signal already exists —
            // otherwise one form caption paints every control in the container.
            if ($score >= 40) {
                $cx = $tx + $tw / 2;
                $cy = $ty + $th / 2;
                $lcx = $lx + $lw / 2;
                $lcy = $ly + $lh / 2;
                $dist = hypot($cx - $lcx, $cy - $lcy);
                if ($dist < 160) {
                    $score += (int) max(0, 10 - $dist / 30);
                }
            }
        } elseif ($nameScore >= 40) {
            $score += 5;
        }

        $caption = $this->unwrap((string) $label->getProperty('Text'));
        if ($caption !== '' && mb_strlen($caption) <= 48) {
            $score += 5;
        }
        if ($caption !== '' && str_ends_with(rtrim($caption), ':')) {
            $score += 8;
        }

        if ($this->parentPath($target->path) === $this->parentPath($label->path)) {
            $score += 12;
        }

        return $score;
    }

    private function namePairScore(string $inputName, string $labelName): int
    {
        $a = $this->nameStem($inputName);
        $b = $this->nameStem($labelName);
        if ($a === '' || $b === '') {
            return 0;
        }
        if ($a === $b) {
            return 70;
        }
        if (str_starts_with($a, $b) || str_starts_with($b, $a)) {
            $shorter = min(strlen($a), strlen($b));
            if ($shorter >= 3) {
                return 45;
            }
        }
        similar_text($a, $b, $percent);
        if ($percent >= 80) {
            return 35;
        }
        if ($percent >= 65) {
            return 20;
        }

        return 0;
    }

    private function nameStem(string $name): string
    {
        $n = $name;
        $n = preg_replace(
            '/^(lbl|txt|txt_|inp|input|cmb|cbo|ddl|drp|btn|img|ico|chk|tgl)+/i',
            '',
            $n
        ) ?? $n;
        $n = preg_replace(
            '/(Label|Lbl|TextInput|TextBox|Input|Field|ComboBox|Combo|Dropdown|DropDown|DatePicker|Picker|CheckBox|Checkbox|Check|Toggle|Slider|Gallery|Button|Icon|Image)$/i',
            '',
            $n
        ) ?? $n;
        $n = preg_replace('/\d+$/', '', $n) ?? $n;
        $n = strtolower(preg_replace('/[^A-Za-z0-9]+/', '', $n) ?? $n);

        return $n;
    }

    private function layoutNumber(ControlNode $control, string $prop): ?float
    {
        $raw = $control->getProperty($prop);
        if ($raw === null) {
            return null;
        }
        $v = trim($this->unwrap($raw));
        if ($v === '' || !preg_match('/^-?\d+(\.\d+)?$/', $v)) {
            return null;
        }

        return (float) $v;
    }

    private function isLabelLike(ControlNode $control): bool
    {
        $t = strtolower($control->type);
        if (str_contains($t, 'button') || str_contains($t, 'input') || str_contains($t, 'dropdown')) {
            return false;
        }
        foreach (['label', 'htmltext', 'htmlviewer', 'classic/label', 'textlabel'] as $needle) {
            if (str_contains($t, $needle)) {
                return true;
            }
        }
        if (preg_match('/(^|\/)text@/i', $control->type) === 1 && !$control->isInteractive()) {
            return true;
        }

        return false;
    }

    private function isSafeControlRef(string $name): bool
    {
        return $name !== '' && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) === 1;
    }

    private function parentPath(string $path): ?string
    {
        $pos = strrpos($path, '/');
        if ($pos === false || $pos === 0) {
            return null;
        }

        return substr($path, 0, $pos);
    }

    /**
     * Labels that were previously written as a stringified copy of Text/Tooltip (no live binding),
     * or as humanized Studio junk names ("Button 1") that should yield to purpose speech.
     */
    private function isBrokenLabel(string $existing, ControlNode $control): bool
    {
        $unwrapped = $this->unwrap($existing);
        if ($unwrapped === '') {
            return true;
        }
        if ($this->isDynamicExpression('=' . $unwrapped) || preg_match('/^\s*If\s*\(/i', $unwrapped)) {
            return true;
        }
        foreach (['Text', 'Tooltip', 'HintText'] as $prop) {
            $src = $control->getProperty($prop);
            if ($src === null || $this->isBlank($src)) {
                continue;
            }
            if ($this->isDynamicExpression($src) && $this->unwrap($src) === $unwrapped) {
                return true;
            }
        }
        if (preg_match('/^Self\.(Text|Tooltip|HintText)\s*$/i', $unwrapped)) {
            return false;
        }
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*\.Text\s*$/', $unwrapped)) {
            return false;
        }
        if (preg_match('/^"[^"]*"\s*&\s*[A-Za-z_][A-Za-z0-9_]*\.(Text|Tooltip|HintText)\s*$/', $unwrapped)) {
            return false;
        }
        if (preg_match('/^"[^"]*"\s*&\s*Self\.(Text|Tooltip|HintText)\s*$/i', $unwrapped)) {
            return false;
        }

        // Stale humanized control-name labels — especially Studio junk ("Button 1").
        $humanized = $this->humanizeIdentifier($control->name);
        if ($humanized !== '' && strcasecmp($unwrapped, $humanized) === 0) {
            if ($this->isStudioGenericName($control->name)) {
                return true;
            }
            foreach (['Text', 'Tooltip', 'HintText'] as $prop) {
                $src = $control->getProperty($prop);
                if ($src !== null && !$this->isBlank($src)) {
                    return true;
                }
            }
            if ($this->labelFromNeighbors($control) !== null
                || $this->iconDescription($control) !== null
                || $this->actionPhrase($control) !== null
            ) {
                return true;
            }
        }

        // Weak role-only label when we now have caption/icon/action context.
        $role = $this->rolePhrase($control);
        if ($role !== '' && strcasecmp($unwrapped, $role) === 0) {
            if ($this->labelFromNeighbors($control) !== null
                || $this->iconDescription($control) !== null
                || $this->actionPhrase($control) !== null
                || $this->purposeFromControlName($control) !== null
            ) {
                return true;
            }
        }

        return false;
    }

    private function isDynamicExpression(string $value): bool
    {
        $v = trim($value);
        if (str_starts_with($v, '=')) {
            $v = trim(substr($v, 1));
        }
        if ($v === '') {
            return false;
        }
        if (
            (str_starts_with($v, '"') && str_ends_with($v, '"') && substr_count($v, '"') === 2)
            || (str_starts_with($v, "'") && str_ends_with($v, "'") && substr_count($v, "'") === 2)
        ) {
            return false;
        }

        return (bool) preg_match(
            '/\b(If|Switch|LookUp|Coalesce|Concatenate|With|Filter|LookUp)\s*\(|\b(Self|Parent|ThisItem|var[A-Z]|com[A-Z]|gbl[A-Z])\b|[()&]|[A-Za-z_]\w*\./i',
            $v
        );
    }

    private function formulaRef(ControlNode $control, string $expr): string
    {
        return $control->format === 'yaml' ? '=' . $expr : $expr;
    }

    private function quotedLiteral(ControlNode $control, string $label): string
    {
        $escaped = str_replace('"', '""', $label);

        return $control->format === 'yaml'
            ? '="' . $escaped . '"'
            : '"' . $escaped . '"';
    }

    private function isBlank(string $value): bool
    {
        $v = trim($this->unwrap($value));

        return $v === '' || strtolower($v) === 'blank()' || $v === '""' || $v === "''";
    }

    private function unwrap(string $value): string
    {
        $v = trim($value);
        if (str_starts_with($v, '=')) {
            $v = substr($v, 1);
        }
        $v = trim($v);
        if ((str_starts_with($v, '"') && str_ends_with($v, '"')) || (str_starts_with($v, "'") && str_ends_with($v, "'"))) {
            $v = substr($v, 1, -1);
            $v = str_replace('""', '"', $v);
        }

        return trim($v);
    }
}
