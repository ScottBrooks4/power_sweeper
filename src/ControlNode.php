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

    public function setProperty(string $name, string $value, string $category = 'Data'): void
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
                    $this->touch();
                    return;
                }
            }
            $node->Rules[] = (object) [
                'Property' => $name,
                'Category' => $category,
                'InvariantScript' => $this->stripEquals($value),
                'RuleProviderType' => 'Unknown',
            ];
            $this->touch();
            return;
        }

        $node->{$name} = $value;
        $this->touch();
    }

    public function getStyleName(): ?string
    {
        if ($this->format !== 'json' || !is_object($this->node)) {
            return null;
        }

        return isset($this->node->StyleName) ? (string) $this->node->StyleName : null;
    }

    public function clearStyleName(): void
    {
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
        foreach (['button', 'icon', 'image', 'toggle', 'checkbox', 'dropdown', 'combobox', 'textinput', 'datepicker', 'slider', 'radio', 'link', 'modernbutton'] as $needle) {
            if (str_contains($t, $needle)) {
                return true;
            }
        }
        return false;
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
    public function addYamlChild(string $name, array $body): void
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
        $this->node['Children'][] = [$name => $body];
        $this->touch();
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
