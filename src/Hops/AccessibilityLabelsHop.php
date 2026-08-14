<?php

declare(strict_types=1);

namespace PowerSweeper\Hops;

use PowerSweeper\ControlDocument;
use PowerSweeper\ControlNode;
use PowerSweeper\HopOptions;
use PowerSweeper\Report;

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
        return 'Fill missing AccessibleLabel on interactive controls from Text/Tooltip/HintText, child labels, and neighboring Label controls (above/left or name-paired). Dynamic sources bind live (Self.Text / Neighbor.Text).';
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
        // 1) Own visible / hint text
        $text = $control->getProperty('Text');
        if ($text !== null && !$this->isBlank($text)) {
            if ($this->isDynamicExpression($text)) {
                return $this->formulaRef($control, 'Self.Text');
            }
            return $this->quotedLiteral($control, $this->unwrap($text));
        }

        foreach (['Tooltip', 'HintText', 'ContentLanguage'] as $prop) {
            $val = $control->getProperty($prop);
            if ($val === null || $this->isBlank($val)) {
                continue;
            }
            if ($this->isDynamicExpression($val)) {
                return $this->formulaRef($control, 'Self.' . $prop);
            }
            return $this->quotedLiteral($control, $this->unwrap($val));
        }

        // 2) Nested label children (buttons wrapping a Label, etc.)
        $fromChild = $this->labelFromChildren($control);
        if ($fromChild !== null) {
            return $fromChild;
        }

        // 3) Neighboring labels (siblings / parent peers) — critical for inputs
        $fromNeighbor = $this->labelFromNeighbors($control);
        if ($fromNeighbor !== null) {
            return $fromNeighbor;
        }

        // 4) Humanize control name: NewRequestButton -> New Request Button
        $name = preg_replace('/([a-z])([A-Z])/', '$1 $2', $control->name) ?? $control->name;
        $name = trim(preg_replace('/[_\-]+/', ' ', $name) ?? $name);
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? $name);
        // Strip trailing type noise for inputs: "Email Input" stays; bare "Text Input 1" is weak but ok
        return $name !== '' ? $this->quotedLiteral($control, $name) : null;
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
                // Prefer a live sibling-style ref when the child has a usable name.
                if ($this->isSafeControlRef($child->name)) {
                    return $this->formulaRef($control, $child->name . '.Text');
                }
                $clean = $this->unwrap($childText);
                if ($clean !== '' && !$this->isDynamicExpression('=' . $clean)) {
                    return $this->quotedLiteral($control, $clean);
                }
                continue;
            }
            return $this->quotedLiteral($control, $this->unwrap($childText));
        }

        return null;
    }

    private function labelFromNeighbors(ControlNode $control): ?string
    {
        $candidates = $this->neighborLabelCandidates($control);
        if ($candidates === []) {
            return null;
        }

        // Highest score first.
        usort($candidates, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);
        $best = $candidates[0];
        if ($best['score'] < 20) {
            return null;
        }

        /** @var ControlNode $label */
        $label = $best['control'];
        $text = $label->getProperty('Text');
        if ($text === null || $this->isBlank($text)) {
            return null;
        }

        if ($this->isDynamicExpression($text) && $this->isSafeControlRef($label->name)) {
            return $this->formulaRef($control, $label->name . '.Text');
        }
        if ($this->isDynamicExpression($text)) {
            return null;
        }

        return $this->quotedLiteral($control, $this->unwrap($text));
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
            // Also consider labels that are children of sibling containers (common form layout).
            foreach ($this->byParent[$parent] ?? [] as $sib) {
                if ($sib->path === $control->path || !$sib->isContainer()) {
                    continue;
                }
                foreach ($sib->children as $nibling) {
                    $pool[] = $nibling;
                }
            }
        }

        // Parent's siblings (labels sitting beside a field container).
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

        // Name pairing: EmailLabel ↔ EmailInput / lblEmail ↔ txtEmail
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

            // Label above input (classic form): overlapping X, label ends near/above input Y.
            if ($overlapX >= min($tw, $lw) * 0.35 && $labelBottom <= $ty + 12 && $ty - $labelBottom <= 80) {
                $score += 55;
                $gap = $ty - $labelBottom;
                $score += (int) max(0, 25 - $gap / 2);
            }

            // Label to the left of input: overlapping Y, label ends left of input.
            if ($overlapY >= min($th, $lh) * 0.35 && $labelRight <= $tx + 12 && $tx - $labelRight <= 160) {
                $score += 50;
                $gap = $tx - $labelRight;
                $score += (int) max(0, 20 - $gap / 4);
            }

            // Caption to the right of icon/button (toolbar / chip pattern).
            $t = strtolower($target->type);
            $isIconOrImage = str_contains($t, 'icon') || (str_contains($t, 'image') && !str_contains($t, 'input'));
            if (
                $isIconOrImage
                && $overlapY >= min($th, $lh) * 0.35
                && $lx >= $targetRight - 12
                && $lx - $targetRight <= 160
            ) {
                $score += 48;
                $gap = $lx - $targetRight;
                $score += (int) max(0, 18 - $gap / 4);
            }

            // Same row / column proximity without clear above/left still gets a small boost.
            $cx = $tx + $tw / 2;
            $cy = $ty + $th / 2;
            $lcx = $lx + $lw / 2;
            $lcy = $ly + $lh / 2;
            $dist = hypot($cx - $lcx, $cy - $lcy);
            if ($dist < 220) {
                $score += (int) max(0, 18 - $dist / 20);
            }
        } elseif ($nameScore >= 40) {
            // No layout numbers — lean on name pairing alone.
            $score += 5;
        }

        // Prefer labels that look like captions (short, end with colon optional).
        $caption = $this->unwrap((string) $label->getProperty('Text'));
        if ($caption !== '' && mb_strlen($caption) <= 48) {
            $score += 5;
        }
        if ($caption !== '' && str_ends_with(rtrim($caption), ':')) {
            $score += 8;
        }

        // Same immediate parent is stronger than uncle/cousin.
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
        // Drop common type/role prefixes & suffixes.
        $n = preg_replace(
            '/^(lbl|txt|txt_|inp|input|cmb|cbo|ddl|drp|btn|img|ico)+/i',
            '',
            $n
        ) ?? $n;
        $n = preg_replace(
            '/(Label|Lbl|TextInput|TextBox|Input|Field|ComboBox|Combo|Dropdown|DropDown|DatePicker|Picker|CheckBox|Checkbox|Toggle|Slider|Gallery|Button|Icon|Image)$/i',
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
        // Modern "Text" display controls that are not inputs.
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
     * Labels that were previously written as a stringified copy of Text/Tooltip (no live binding).
     */
    private function isBrokenLabel(string $existing, ControlNode $control): bool
    {
        $unwrapped = $this->unwrap($existing);
        if ($unwrapped === '') {
            return true;
        }
        // Classic failure mode: AccessibleLabel = "If(varLang,""Save"",""Enregistrer"")"
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
        // Self.* and Neighbor.Text bindings are good.
        if (preg_match('/^Self\.(Text|Tooltip|HintText)\s*$/i', $unwrapped)) {
            return false;
        }
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*\.Text\s*$/', $unwrapped)) {
            return false;
        }

        // Stale humanized control-name labels lose to a real Text/Tooltip/HintText/neighbor source.
        $humanized = preg_replace('/([a-z])([A-Z])/', '$1 $2', $control->name) ?? $control->name;
        $humanized = trim(preg_replace('/\s+/', ' ', trim(preg_replace('/[_\-]+/', ' ', $humanized) ?? $humanized)) ?? $humanized);
        if (strcasecmp($unwrapped, $humanized) === 0) {
            foreach (['Text', 'Tooltip', 'HintText'] as $prop) {
                $src = $control->getProperty($prop);
                if ($src !== null && !$this->isBlank($src)) {
                    return true;
                }
            }
            if ($this->labelFromNeighbors($control) !== null) {
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
        // Simple quoted string is not dynamic.
        if (
            (str_starts_with($v, '"') && str_ends_with($v, '"') && substr_count($v, '"') === 2)
            || (str_starts_with($v, "'") && str_ends_with($v, "'") && substr_count($v, "'") === 2)
        ) {
            return false;
        }
        // Function calls / operators / known dynamic roots (avoid bare words like "User reviewed").
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
            // Undo Power Fx doubling inside string literals.
            $v = str_replace('""', '"', $v);
        }
        return trim($v);
    }
}
