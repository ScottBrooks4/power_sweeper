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
