<?php

declare(strict_types=1);

namespace PowerSweeper;

/**
 * Unified view of a single control inside a YAML or JSON document.
 *
 * YAML nodes are associative arrays. JSON nodes are stdClass trees so that
 * Studio empty objects (`{}`) are never collapsed to PHP empty arrays (`[]`).
 */
final class ControlNode
{
    /** @var array<string, mixed>|object */
    private array|object $node;

    /**
     * @param array<string, mixed>|object $node Mutable view into the document tree
     * @param list<ControlNode> $children
     */
    public function __construct(
        public string $name,
        public string $type,
        public string $path,
        public string $format,
        array|object &$node,
        public array $children = [],
        private ?MutationTracker $mutations = null,
    ) {
        $this->node = &$node;
    }

    private function touch(): void
    {
        $this->mutations?->mark();
    }

    public function getProperty(string $name): ?string
    {
        if ($this->format === 'yaml') {
            $props = is_array($this->node) ? ($this->node['Properties'] ?? null) : null;
            if (!is_array($props) || !array_key_exists($name, $props)) {
                return null;
            }
            return self::stringify($props[$name]);
        }

        $node = $this->jsonNode();

        if (isset($node->Rules) && is_array($node->Rules)) {
            foreach ($node->Rules as $rule) {
                if (is_object($rule) && ($rule->Property ?? null) === $name) {
                    return self::stringify($rule->InvariantScript ?? $rule->Value ?? null);
                }
            }
        }

        if (isset($node->PropertyValues) && is_array($node->PropertyValues)) {
            foreach ($node->PropertyValues as $pv) {
                if (is_object($pv) && ($pv->Property ?? null) === $name) {
                    return self::stringify($pv->Value ?? null);
                }
            }
        }

        if (property_exists($node, $name)) {
            return self::stringify($node->{$name});
        }

        return null;
    }

    public function hasProperty(string $name): bool
    {
        return $this->getProperty($name) !== null;
    }

    public function setProperty(string $name, string $value, string $category = 'Data', ?string $provider = null): void
    {
        if ($this->format === 'yaml') {
            if (!is_array($this->node)) {
                return;
            }
            if (!isset($this->node['Properties']) || !is_array($this->node['Properties'])) {
                $this->node['Properties'] = [];
            }
            $this->node['Properties'][$name] = $value;
            $this->touch();
            return;
        }

        $node = $this->jsonNode();

        if (isset($node->Rules) && is_array($node->Rules)) {
            foreach ($node->Rules as $i => $rule) {
                if (is_object($rule) && ($rule->Property ?? null) === $name) {
                    $rule->InvariantScript = $this->stripEquals($value);
                    if ($provider !== null) {
                        $rule->RuleProviderType = $provider;
                    }
                    $this->touch();
                    return;
                }
            }
            $node->Rules[] = (object) [
                'Property' => $name,
                'Category' => $category,
                'InvariantScript' => $this->stripEquals($value),
                'RuleProviderType' => $provider ?? 'Unknown',
            ];
            if (isset($node->ControlPropertyState) && is_array($node->ControlPropertyState)) {
                $known = false;
                foreach ($node->ControlPropertyState as $state) {
                    if (is_object($state) && ($state->InvariantPropertyName ?? null) === $name) {
                        $known = true;
                        break;
                    }
                }
                if (!$known) {
                    $node->ControlPropertyState[] = (object) [
                        'InvariantPropertyName' => $name,
                        'AutoRuleBindingEnabled' => false,
                        'AutoRuleBindingString' => '',
                        'NameMapSourceSchema' => '?',
                        'IsLockable' => false,
                        'AFDDataSourceName' => '',
                    ];
                }
            }
            $this->touch();
            return;
        }

        $node->{$name} = $value;
        $this->touch();
    }

    public function getStyleName(): ?string
    {
        if ($this->format === 'yaml' && is_array($this->node)) {
            $style = $this->node['StyleName'] ?? null;
            if ($style === null && isset($this->node['Properties']) && is_array($this->node['Properties'])) {
                $style = $this->node['Properties']['StyleName'] ?? null;
            }
            return is_string($style) || is_numeric($style) ? (string) $style : null;
        }
        if ($this->format !== 'json' || !is_object($this->node)) {
            return null;
        }

        return isset($this->node->StyleName) ? (string) $this->node->StyleName : null;
    }

    public function setStyleName(string $style): void
    {
        if ($this->format === 'yaml' && is_array($this->node)) {
            $this->node['StyleName'] = $style;
            $this->touch();
            return;
        }
        if ($this->format !== 'json' || !is_object($this->node)) {
            return;
        }
        $this->node->StyleName = $style;
        $this->touch();
    }

    public function clearStyleName(): void
    {
        if ($this->format === 'yaml' && is_array($this->node)) {
            $cleared = false;
            if (array_key_exists('StyleName', $this->node)) {
                unset($this->node['StyleName']);
                $cleared = true;
            }
            if (isset($this->node['Properties']) && is_array($this->node['Properties'])
                && array_key_exists('StyleName', $this->node['Properties'])
            ) {
                unset($this->node['Properties']['StyleName']);
                $cleared = true;
            }
            if ($cleared) {
                $this->touch();
            }
            return;
        }
        if ($this->format !== 'json' || !is_object($this->node)) {
            return;
        }
        if (property_exists($this->node, 'StyleName')) {
            unset($this->node->StyleName);
            $this->touch();
        }
    }

    /** YAML component/screen definition field outside Properties (e.g. AccessAppScope). */
    public function getYamlDefinitionField(string $name): mixed
    {
        if ($this->format !== 'yaml' || !is_array($this->node)) {
            return null;
        }

        return $this->node[$name] ?? null;
    }

    public function setYamlDefinitionField(string $name, mixed $value): void
    {
        if ($this->format !== 'yaml' || !is_array($this->node)) {
            return;
        }
        $this->node[$name] = $value;
        $this->touch();
    }

    /**
     * Change this control's template (classic ↔ modern prep).
     *
     * YAML writes the Control: field. JSON updates Template.Id/Name/Version.
     * Callers should ControlDocument::reindex() after structural batch edits.
     *
     * @param array{Id?:string,Name?:string,Version?:string} $jsonTemplate
     */
    public function setControlType(string $yamlControl, array $jsonTemplate = [], bool $dropDynamicDefinition = false): void
    {
        if ($this->format === 'yaml') {
            if (!is_array($this->node)) {
                return;
            }
            $this->node['Control'] = $yamlControl;
            $this->type = $yamlControl;
            $this->touch();
            return;
        }

        $node = $this->jsonNode();
        if (!isset($node->Template) || !is_object($node->Template)) {
            $node->Template = (object) [];
        }
        if (isset($jsonTemplate['Id'])) {
            $node->Template->Id = (string) $jsonTemplate['Id'];
        }
        if (isset($jsonTemplate['Name'])) {
            $node->Template->Name = (string) $jsonTemplate['Name'];
        }
        if (isset($jsonTemplate['Version'])) {
            $node->Template->Version = (string) $jsonTemplate['Version'];
        }
        // PCF DynamicControlDefinitionJson blobs are multi-MB on large screens.
        // Once the template is classic, Studio ignores them — drop to cut RAM/time.
        if ($dropDynamicDefinition && isset($node->DynamicControlDefinitionJson)) {
            unset($node->DynamicControlDefinitionJson);
        }
        $this->type = isset($jsonTemplate['Name'])
            ? (string) $jsonTemplate['Name']
            : $yamlControl;
        $this->touch();
    }

    public function removeProperty(string $name): void
    {
        if ($this->format === 'yaml') {
            if (is_array($this->node) && isset($this->node['Properties']) && is_array($this->node['Properties'])) {
                unset($this->node['Properties'][$name]);
                $this->touch();
            }
            return;
        }

        $node = $this->jsonNode();
        if (isset($node->Rules) && is_array($node->Rules)) {
            $before = count($node->Rules);
            $node->Rules = array_values(array_filter(
                $node->Rules,
                static fn($rule) => !(is_object($rule) && ($rule->Property ?? null) === $name)
            ));
            if (count($node->Rules) !== $before) {
                $this->touch();
            }
        }
    }

    public function isContainer(): bool
    {
        $t = strtolower($this->type);
        return str_contains($t, 'container')
            || str_contains($t, 'groupcontainer')
            || in_array($t, ['group', 'groupcontainer'], true);
    }

    public function isInteractive(): bool
    {
        $t = strtolower($this->type);
        // JSON classic TextInput templates are named "text" (not "textinput").
        // Galleries are flagged by App checker for AccessibleLabel / TabIndex / focus.
        if ($t === 'text' || $t === 'textarea') {
            return true;
        }
        foreach ([
            'button', 'icon', 'image', 'toggle', 'checkbox', 'dropdown', 'combobox',
            'textinput', 'datepicker', 'slider', 'radio', 'link', 'modernbutton',
            'gallery', 'listbox', 'attachments', 'comboboxcanvas', 'buttoncanvas',
        ] as $needle) {
            if (str_contains($t, $needle)) {
                return true;
            }
        }
        return false;
    }

    /**
     * True when the control has a real action formula (not blank / false).
     * Labels, rectangles, and shapes become keyboard targets when OnSelect is set.
     */
    public function hasActionHandler(): bool
    {
        foreach (['OnSelect', 'OnChange', 'OnCheck', 'OnUncheck'] as $prop) {
            $raw = $this->getProperty($prop);
            if ($raw === null) {
                continue;
            }
            $trim = strtolower(trim(ltrim(trim($raw), '=')));
            if ($trim === '' || $trim === 'false' || $trim === 'true' || $trim === 'blank()' || $trim === '""' || $trim === "''") {
                continue;
            }
            return true;
        }
        return false;
    }

    /**
     * Parsed TabIndex, or null when unset / non-numeric / formula.
     */
    public function tabIndexValue(): ?int
    {
        $tab = $this->getProperty('TabIndex');
        if ($tab === null) {
            return null;
        }
        $trim = trim(ltrim(trim($tab), '='));
        if ($trim === '' || !is_numeric($trim)) {
            return null;
        }
        return (int) $trim;
    }

    /**
     * Power Sweeper a11y pattern: TabIndex = If(gblA11yTabStops, 0, -1).
     * Clears App checker while letting sighted users leave decorative controls
     * out of the tab order until they enable enhanced keyboard / screen-reader stops.
     */
    public function hasConditionalA11yTabIndex(string $var = 'gblA11yTabStops'): bool
    {
        $tab = $this->getProperty('TabIndex');
        if ($tab === null) {
            return false;
        }
        $trim = trim(ltrim(trim($tab), '='));

        return (bool) preg_match(
            '/^If\s*\(\s*' . preg_quote($var, '/') . '\s*,\s*0\s*,\s*-1\s*\)$/i',
            $trim
        );
    }

    /** True when TabIndex is a stop (>= 0) or the conditional a11y formula. */
    public function tabIndexSatisfiesChecker(string $var = 'gblA11yTabStops'): bool
    {
        if ($this->hasConditionalA11yTabIndex($var)) {
            return true;
        }
        $v = $this->tabIndexValue();

        return $v !== null && $v >= 0;
    }

    /**
     * Controls App checker treats as needing AccessibleLabel / TabIndex / focus:
     * inherent interactive templates, action handlers, or an explicit TabIndex
     * (including -1 or If(gblA11yTabStops, 0, -1) — Studio's "Missing tab stop" on Labels/Images).
     */
    public function isAccessibilityInteractive(): bool
    {
        if ($this->isApp() || $this->isScreen()) {
            return false;
        }
        if ($this->isInteractive() || $this->hasActionHandler()) {
            return true;
        }
        // Explicit TabIndex (0+, -1, or conditional a11y formula) puts the control in Studio's focus model.
        return $this->tabIndexValue() !== null || $this->hasConditionalA11yTabIndex();
    }

    public function isButtonLike(): bool
    {
        $t = strtolower($this->type);
        return str_contains($t, 'button');
    }

    public function isScreen(): bool
    {
        $t = strtolower($this->type);
        return str_starts_with($t, 'screen') || $t === 'screen';
    }

    public function isApp(): bool
    {
        $t = strtolower($this->type);
        return str_starts_with($t, 'app') || strtolower($this->name) === 'app';
    }

    /** YAML Variant (AutoLayout, ManualLayout, …) or JSON Template.Variant. */
    public function getVariant(): ?string
    {
        if ($this->format === 'yaml') {
            $v = $this->getYamlDefinitionField('Variant');
            return is_string($v) && $v !== '' ? $v : null;
        }
        if ($this->format !== 'json' || !is_object($this->node)) {
            return null;
        }
        $template = $this->node->Template ?? null;
        if (is_object($template) && isset($template->Variant) && is_string($template->Variant) && $template->Variant !== '') {
            return $template->Variant;
        }

        return isset($this->node->Variant) && is_string($this->node->Variant) && $this->node->Variant !== ''
            ? $this->node->Variant
            : null;
    }

    public function isToggle(): bool
    {
        $t = strtolower($this->type);
        return str_contains($t, 'toggle');
    }

    public function isRadio(): bool
    {
        $t = strtolower($this->type);
        return str_contains($t, 'radio');
    }

    /** @return list<string> */
    public function propertyNames(): array
    {
        if ($this->format === 'yaml') {
            $props = is_array($this->node) ? ($this->node['Properties'] ?? []) : [];
            return is_array($props) ? array_map('strval', array_keys($props)) : [];
        }

        $names = [];
        $node = $this->jsonNode();
        if (isset($node->Rules) && is_array($node->Rules)) {
            foreach ($node->Rules as $rule) {
                if (is_object($rule) && isset($rule->Property)) {
                    $names[] = (string) $rule->Property;
                }
            }
        }
        return array_values(array_unique($names));
    }

    /**
     * Append a Power Fx statement to a chaining property (OnStart, OnVisible, OnCheck, …).
     */
    public function appendStatement(string $property, string $statement, string $chain = ';'): void
    {
        $statement = trim($statement);
        $statement = ltrim($statement, '=');
        $existing = $this->getProperty($property);
        if ($existing === null || trim($existing) === '' || trim($existing) === '=') {
            $to = $this->format === 'yaml' ? '=' . $statement : $statement;
            $this->setProperty($property, $to);
            return;
        }

        $body = ltrim(trim($existing), '=');
        $body = rtrim($body);
        // Avoid duplicating the same statement
        if (str_contains($body, $statement)) {
            return;
        }
        if (!str_ends_with($body, $chain) && !str_ends_with($body, $chain . $chain)) {
            $body .= $chain;
        }
        $body .= ' ' . $statement;
        $this->setProperty($property, $this->format === 'yaml' ? '=' . $body : $body);
    }

    /**
     * Add a YAML child control under this node (no-op for JSON format).
     *
     * @param array<string, mixed> $body
     */
    public function addYamlChild(string $name, array $body, ?string $beforeSibling = null): void
    {
        if ($this->format !== 'yaml' || !is_array($this->node)) {
            return;
        }
        if (!isset($this->node['Children']) || !is_array($this->node['Children'])) {
            $this->node['Children'] = [];
        }
        // Avoid duplicate names
        foreach ($this->node['Children'] as $item) {
            if (is_array($item) && array_key_exists($name, $item)) {
                return;
            }
        }
        $entry = [$name => $body];
        if (is_string($beforeSibling) && $beforeSibling !== '') {
            foreach ($this->node['Children'] as $i => $item) {
                if (is_array($item) && array_key_exists($beforeSibling, $item)) {
                    array_splice($this->node['Children'], $i, 0, [$entry]);
                    $this->touch();

                    return;
                }
            }
        }
        $this->node['Children'][] = $entry;
        $this->touch();
    }

    /**
     * Append a Studio JSON control, or insert it before a sibling.
     * No-op if a child with the same name exists.
     */
    public function addJsonChild(object $child, ?string $beforeName = null): void
    {
        if ($this->format !== 'json' || !is_object($this->node)) {
            return;
        }
        if (!isset($this->node->Children) || !is_array($this->node->Children)) {
            $this->node->Children = [];
        }
        $name = (string) ($child->Name ?? '');
        foreach ($this->node->Children as $existing) {
            if (is_object($existing) && (string) ($existing->Name ?? '') === $name) {
                return;
            }
        }
        $child->Parent = $this->name;
        if (is_string($beforeName) && $beforeName !== '') {
            foreach ($this->node->Children as $i => $existing) {
                if (is_object($existing) && (string) ($existing->Name ?? '') === $beforeName) {
                    array_splice($this->node->Children, $i, 0, [$child]);
                    $this->touch();

                    return;
                }
            }
        }
        $this->node->Children[] = $child;
        $this->touch();
    }

    /** Deep copy of a JSON control, with a new name and no children. */
    public function cloneJsonControl(string $newName): ?object
    {
        if ($this->format !== 'json' || !is_object($this->node)) {
            return null;
        }
        $copy = json_decode(json_encode($this->node, JSON_THROW_ON_ERROR));
        if (!is_object($copy)) {
            return null;
        }
        $copy->Name = $newName;
        $copy->Children = [];
        if (isset($copy->ControlUniqueId)) {
            $copy->ControlUniqueId = 'ps' . substr(md5($newName), 0, 8);
        }

        return $copy;
    }

    /** Remove a direct YAML child by name. */
    public function removeYamlChild(string $name): bool
    {
        return $this->extractYamlChild($name) !== null;
    }

    /**
     * Remove a direct YAML child and return its body (without the name wrapper).
     *
     * @return array<string, mixed>|null
     */
    public function extractYamlChild(string $name): ?array
    {
        if ($this->format !== 'yaml' || !is_array($this->node)) {
            return null;
        }
        if (!isset($this->node['Children']) || !is_array($this->node['Children'])) {
            return null;
        }
        foreach ($this->node['Children'] as $i => $item) {
            if (is_array($item) && array_key_exists($name, $item) && is_array($item[$name])) {
                $body = $item[$name];
                array_splice($this->node['Children'], $i, 1);
                $this->touch();

                return $body;
            }
        }

        return null;
    }

    /**
     * Remove a YAML descendant by name (depth-first) and return its body.
     *
     * @return array<string, mixed>|null
     */
    public function extractYamlDescendant(string $name): ?array
    {
        $direct = $this->extractYamlChild($name);
        if ($direct !== null) {
            return $direct;
        }
        foreach ($this->children as $child) {
            if ($child instanceof self) {
                $found = $child->extractYamlDescendant($name);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    public function renameJsonName(string $newName): bool
    {
        if ($this->format !== 'json' || !is_object($this->node)) {
            return false;
        }
        if ($newName === $this->name) {
            return false;
        }
        $this->node->Name = $newName;
        $this->name = $newName;
        $this->touch();

        return true;
    }

    private function jsonNode(): object
    {
        if (!is_object($this->node)) {
            throw new \LogicException('JSON ControlNode expected object tree');
        }
        return $this->node;
    }

    private function stripEquals(string $value): string
    {
        return str_starts_with($value, '=') ? substr($value, 1) : $value;
    }

    private static function stringify(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_string($value)) {
            return $value;
        }
        return json_encode($value, JSON_UNESCAPED_SLASHES);
    }
}
