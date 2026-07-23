<?php

declare(strict_types=1);

namespace PowerSweeper;

/**
 * Unified view of a single control inside a YAML or JSON document.
 */
final class ControlNode
{
    /**
     * @param array<string, mixed> $node Mutable reference into the document tree
     * @param list<ControlNode> $children
     */
    public function __construct(
        public string $name,
        public string $type,
        public string $path,
        public string $format,
        private array &$node,
        public array $children = [],
    ) {
    }

    public function getProperty(string $name): ?string
    {
        if ($this->format === 'yaml') {
            $props = $this->node['Properties'] ?? null;
            if (!is_array($props) || !array_key_exists($name, $props)) {
                return null;
            }
            return self::stringify($props[$name]);
        }

        // JSON Rules / PropertyValues style
        if (isset($this->node['Rules']) && is_array($this->node['Rules'])) {
            foreach ($this->node['Rules'] as $rule) {
                if (is_array($rule) && ($rule['Property'] ?? null) === $name) {
                    return self::stringify($rule['InvariantScript'] ?? $rule['Value'] ?? null);
                }
            }
        }

        if (isset($this->node['PropertyValues']) && is_array($this->node['PropertyValues'])) {
            foreach ($this->node['PropertyValues'] as $pv) {
                if (is_array($pv) && ($pv['Property'] ?? null) === $name) {
                    return self::stringify($pv['Value'] ?? null);
                }
            }
        }

        if (array_key_exists($name, $this->node)) {
            return self::stringify($this->node[$name]);
        }

        return null;
    }

    public function hasProperty(string $name): bool
    {
        return $this->getProperty($name) !== null;
    }

    public function setProperty(string $name, string $value): void
    {
        if ($this->format === 'yaml') {
            if (!isset($this->node['Properties']) || !is_array($this->node['Properties'])) {
                $this->node['Properties'] = [];
            }
            $this->node['Properties'][$name] = $value;
            return;
        }

        if (isset($this->node['Rules']) && is_array($this->node['Rules'])) {
            foreach ($this->node['Rules'] as $i => $rule) {
                if (is_array($rule) && ($rule['Property'] ?? null) === $name) {
                    $this->node['Rules'][$i]['InvariantScript'] = $this->stripEquals($value);
                    return;
                }
            }
            $this->node['Rules'][] = [
                'Property' => $name,
                'Category' => 'Data',
                'InvariantScript' => $this->stripEquals($value),
                'RuleProviderType' => 'Unknown',
            ];
            return;
        }

        $this->node[$name] = $value;
    }

    public function removeProperty(string $name): void
    {
        if ($this->format === 'yaml') {
            if (isset($this->node['Properties']) && is_array($this->node['Properties'])) {
                unset($this->node['Properties'][$name]);
            }
            return;
        }

        if (isset($this->node['Rules']) && is_array($this->node['Rules'])) {
            $this->node['Rules'] = array_values(array_filter(
                $this->node['Rules'],
                static fn($rule) => !(is_array($rule) && ($rule['Property'] ?? null) === $name)
            ));
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

    /** @return list<string> */
    public function propertyNames(): array
    {
        if ($this->format === 'yaml') {
            $props = $this->node['Properties'] ?? [];
            return is_array($props) ? array_map('strval', array_keys($props)) : [];
        }

        $names = [];
        if (isset($this->node['Rules']) && is_array($this->node['Rules'])) {
            foreach ($this->node['Rules'] as $rule) {
                if (is_array($rule) && isset($rule['Property'])) {
                    $names[] = (string) $rule['Property'];
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
        if ($this->format !== 'yaml') {
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
