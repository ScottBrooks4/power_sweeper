<?php

declare(strict_types=1);

namespace PowerSweeper;

use Symfony\Component\Yaml\Yaml;

final class ControlDocument
{
    /** @var list<ControlNode> */
    private array $controls = [];

    private MutationTracker $mutations;

    private string $yamlHeader = '';

    private string $jsonNewline = "\r\n";

    private int $jsonIndent = 2;

    /**
     * @param array<mixed>|object $data YAML trees are arrays; JSON trees are objects.
     */
    public function __construct(
        public readonly string $relativePath,
        public readonly string $format,
        private array|object $data,
        string $yamlHeader = '',
        string $jsonNewline = "\r\n",
        int $jsonIndent = 2,
    ) {
        $this->mutations = new MutationTracker();
        $this->yamlHeader = $yamlHeader;
        $this->jsonNewline = $jsonNewline;
        $this->jsonIndent = $jsonIndent;
        $this->rebuildControls();
    }

    public static function fromFile(string $absolutePath, string $relativePath): ?self
    {
        $lower = strtolower($relativePath);
        if (str_ends_with($lower, '.pa.yaml') || str_ends_with($lower, '.fx.yaml') || str_ends_with($lower, '.yaml') || str_ends_with($lower, '.yml')) {
            $raw = file_get_contents($absolutePath);
            if ($raw === false) {
                return null;
            }
            $data = Yaml::parse($raw) ?? [];
            if (!is_array($data)) {
                return null;
            }
            return new self($relativePath, 'yaml', $data, PowerAppsYaml::extractHeaderComment($raw));
        }

        if (str_ends_with($lower, '.json')) {
            // Skip noisy non-control JSON
            $base = basename($lower);
            if (in_array($base, ['checksum.json', 'entropy.json', 'canvasmanifest.json', 'themes.json'], true)) {
                return null;
            }
            if (str_contains($lower, '/connections/') || str_contains($lower, '/datasources/') || str_contains($lower, '/pkgs/')) {
                return null;
            }
            $raw = file_get_contents($absolutePath);
            if ($raw === false) {
                return null;
            }
            // Object-mode decode preserves Studio empty objects `{}` (assoc mode turns them into `[]`).
            $data = json_decode($raw);
            if (!is_object($data)) {
                return null;
            }
            // Control trees, or any JSON carrying InvariantScript (internal / classic rules)
            if (!self::looksLikeControlJson($data) && !self::containsInvariantScript($data)) {
                return null;
            }
            $style = StudioJson::detectStyle($raw);
            // If the file was previously rewritten with PHP defaults, prefer Studio export style.
            $newline = $style['newline'];
            $indent = $style['indent'];
            if ($newline === "\n" && $indent === 4) {
                $newline = "\r\n";
                $indent = 2;
            }
            return new self($relativePath, 'json', $data, '', $newline, $indent);
        }

        return null;
    }

    /** @return list<ControlNode> */
    public function controls(): array
    {
        return $this->controls;
    }

    public function isDirty(): bool
    {
        return $this->mutations->isDirty();
    }

    public function markDirty(): void
    {
        $this->mutations->mark();
    }

    public function save(string $absolutePath): void
    {
        if (!$this->mutations->isDirty()) {
            return;
        }

        if ($this->format === 'yaml') {
            if (!is_array($this->data)) {
                throw new \LogicException('YAML ControlDocument expected array tree');
            }
            file_put_contents($absolutePath, PowerAppsYaml::dump($this->data, $this->yamlHeader));
            return;
        }

        file_put_contents(
            $absolutePath,
            StudioJson::encode($this->data, $this->jsonNewline, $this->jsonIndent)
        );
    }

    /** Rebuild the flat control index after structural edits (e.g. injected children). */
    public function reindex(): void
    {
        $this->rebuildControls();
    }

    /**
     * Walk every formula-like string in this document and rewrite via $mapper.
     * Mapper receives (formula, pathLabel) and returns the replacement (or same string).
     *
     * @param callable(string, string): string $mapper
     * @return int number of formulas changed
     */
    public function transformFormulas(callable $mapper): int
    {
        $changed = 0;
        if ($this->format === 'yaml') {
            if (!is_array($this->data)) {
                return 0;
            }
            $this->transformYamlFormulas($this->data, $this->relativePath, $mapper, $changed);
        } else {
            $this->transformJsonFormulas($this->data, $this->relativePath, $mapper, $changed);
        }
        if ($changed > 0) {
            $this->mutations->mark();
            $this->rebuildControls();
        }
        return $changed;
    }

    private function rebuildControls(): void
    {
        $this->controls = [];
        if ($this->format === 'yaml') {
            if (is_array($this->data)) {
                $this->walkYaml($this->data, $this->relativePath);
            }
            return;
        }
        if (is_object($this->data)) {
            $this->walkJson($this->data, $this->relativePath);
        }
    }

    /**
     * @param array<mixed> $data
     * @param callable(string, string): string $mapper
     */
    private function transformYamlFormulas(array &$data, string $path, callable $mapper, int &$changed): void
    {
        foreach ($data as $key => &$value) {
            if (is_string($value) && self::looksLikeFormulaString($value)) {
                $label = $path . '#' . (string) $key;
                $next = $mapper($value, $label);
                if ($next !== $value) {
                    $value = $next;
                    $changed++;
                }
                continue;
            }
            if (!is_array($value)) {
                continue;
            }
            $childPath = $path;
            if (is_string($key)) {
                $childPath .= '/' . $key;
            }
            // Properties map: every value may be a formula
            if ($key === 'Properties' && $this->isAssoc($value)) {
                foreach ($value as $prop => &$propVal) {
                    if (is_string($propVal)) {
                        $label = $childPath . '.' . $prop;
                        $next = $mapper($propVal, $label);
                        if ($next !== $propVal) {
                            $propVal = $next;
                            $changed++;
                        }
                    } elseif (is_array($propVal)) {
                        $this->transformYamlFormulas($propVal, $childPath . '.' . $prop, $mapper, $changed);
                    }
                }
                unset($propVal);
                continue;
            }
            $this->transformYamlFormulas($value, $childPath, $mapper, $changed);
        }
        unset($value);
    }

    /**
     * @param callable(string, string): string $mapper
     */
    private function transformJsonFormulas(object|array &$data, string $path, callable $mapper, int &$changed): void
    {
        if (is_object($data)) {
            foreach (get_object_vars($data) as $key => $value) {
                if (is_string($value) && self::isJsonFormulaField($key)) {
                    $label = $path . '.' . $key;
                    $next = $mapper($value, $label);
                    if ($next !== $value) {
                        $data->{$key} = $next;
                        $changed++;
                    }
                    continue;
                }
                if (is_string($value) && self::looksLikeFormulaString($value)) {
                    if (in_array($key, ['Value', 'Script', 'Expression', 'Formula'], true)) {
                        $label = $path . '.' . $key;
                        $next = $mapper($value, $label);
                        if ($next !== $value) {
                            $data->{$key} = $next;
                            $changed++;
                        }
                    }
                    continue;
                }
                if (is_object($value) || is_array($value)) {
                    $childPath = $path . '/' . $key;
                    $this->transformJsonFormulas($value, $childPath, $mapper, $changed);
                    $data->{$key} = $value;
                }
            }
            return;
        }

        foreach ($data as $key => &$value) {
            if (is_string($value) && self::isJsonFormulaField((string) $key)) {
                $label = $path . '.' . (string) $key;
                $next = $mapper($value, $label);
                if ($next !== $value) {
                    $value = $next;
                    $changed++;
                }
                continue;
            }
            if (is_string($value) && self::looksLikeFormulaString($value)) {
                if (in_array((string) $key, ['Value', 'Script', 'Expression', 'Formula'], true)) {
                    $label = $path . '.' . (string) $key;
                    $next = $mapper($value, $label);
                    if ($next !== $value) {
                        $value = $next;
                        $changed++;
                    }
                }
                continue;
            }
            if (is_object($value) || is_array($value)) {
                $childPath = is_string($key) ? $path . '/' . $key : $path;
                $this->transformJsonFormulas($value, $childPath, $mapper, $changed);
            }
        }
        unset($value);
    }

    /**
     * JSON fields that carry Power Fx (locale corruption shows up here in classic packs).
     */
    private static function isJsonFormulaField(string $key): bool
    {
        return in_array($key, [
            'InvariantScript',
            // Template/auto bindings often retain locale RGBA(...;...) after language switch
            'AutoRuleBindingString',
        ], true);
    }

    private static function looksLikeFormulaString(string $value): bool
    {
        $v = trim($value);
        if ($v === '') {
            return false;
        }
        if (str_starts_with($v, '=')) {
            return true;
        }
        // InvariantScript often omits leading =
        if (preg_match('/^(RGBA?|If|Switch|With|Set|UpdateContext|Navigate|LookUp|Filter|ForAll|Concurrent|ParseJSON|ClearCollect|Collect|Patch|Remove|UpdateIf)\s*\(/i', $v)) {
            return true;
        }
        if (str_contains($v, ';;') || preg_match('/\b[A-Za-z_][\w.]*\s*\([^)]*;/', $v)) {
            return true;
        }
        if (preg_match('/(?<![A-Za-z_.])\d+,\d+/', $v)) {
            return true;
        }
        return false;
    }

    /**
     * @param array<mixed> $data
     */
    private function walkYaml(array &$data, string $pathPrefix): void
    {
        foreach ($data as $key => &$value) {
            if (!is_array($value)) {
                continue;
            }

            // Named control object: Key => [Control => ..., Properties => ..., Children => ...]
            if (isset($value['Control']) || isset($value['Properties']) || isset($value['Children'])) {
                $name = is_string($key) ? $key : ($value['Name'] ?? 'Control');
                $type = (string) ($value['Control'] ?? $value['Template'] ?? 'Unknown');
                $path = $pathPrefix . '/' . $name;
                $children = [];
                if (isset($value['Children']) && is_array($value['Children'])) {
                    $children = $this->extractYamlChildren($value['Children'], $path);
                }
                $node = new ControlNode($name, $type, $path, 'yaml', $value, $children, $this->mutations);
                $this->controls[] = $node;
                continue;
            }

            // List item that wraps a single named control
            if (array_is_list($value)) {
                $this->extractYamlChildren($value, $pathPrefix);
                continue;
            }

            $this->walkYaml($value, $pathPrefix . '/' . (string) $key);
        }
    }

    /**
     * @param list<mixed> $children
     * @return list<ControlNode>
     */
    private function extractYamlChildren(array &$children, string $pathPrefix): array
    {
        $nodes = [];
        foreach ($children as &$item) {
            if (!is_array($item)) {
                continue;
            }
            // Children often: [ { ControlName: { Control: ..., Properties: ... } } ]
            if ($this->isAssoc($item) && !isset($item['Control']) && !isset($item['Properties'])) {
                foreach ($item as $name => &$body) {
                    if (!is_array($body)) {
                        continue;
                    }
                    $type = (string) ($body['Control'] ?? 'Unknown');
                    $path = $pathPrefix . '/' . $name;
                    $grand = [];
                    if (isset($body['Children']) && is_array($body['Children'])) {
                        $grand = $this->extractYamlChildren($body['Children'], $path);
                    }
                    $node = new ControlNode((string) $name, $type, $path, 'yaml', $body, $grand, $this->mutations);
                    $this->controls[] = $node;
                    $nodes[] = $node;
                }
                continue;
            }

            if (isset($item['Control']) || isset($item['Properties'])) {
                $name = (string) ($item['Name'] ?? 'Control');
                $type = (string) ($item['Control'] ?? 'Unknown');
                $path = $pathPrefix . '/' . $name;
                $grand = [];
                if (isset($item['Children']) && is_array($item['Children'])) {
                    $grand = $this->extractYamlChildren($item['Children'], $path);
                }
                $node = new ControlNode($name, $type, $path, 'yaml', $item, $grand, $this->mutations);
                $this->controls[] = $node;
                $nodes[] = $node;
            }
        }
        return $nodes;
    }

    private function walkJson(object $data, string $pathPrefix): void
    {
        if (isset($data->TopParent) && is_object($data->TopParent)) {
            $this->walkJsonControl($data->TopParent, $pathPrefix);
            return;
        }

        if (isset($data->Controls) && is_array($data->Controls)) {
            foreach ($data->Controls as &$control) {
                if (is_object($control)) {
                    $this->walkJsonControl($control, $pathPrefix);
                }
            }
            unset($control);
            return;
        }

        // Single control object
        if (isset($data->Name) && (isset($data->Rules) || isset($data->Template) || isset($data->Children))) {
            $this->walkJsonControl($data, $pathPrefix);
        }
    }

    private function walkJsonControl(object &$control, string $pathPrefix): void
    {
        $this->walkJsonControlReturn($control, $pathPrefix);
    }

    private function walkJsonControlReturn(object &$control, string $pathPrefix): ControlNode
    {
        $name = (string) ($control->Name ?? 'Control');
        $type = 'Unknown';
        if (isset($control->Template) && is_object($control->Template)) {
            $type = (string) ($control->Template->Name ?? $control->Template->Id ?? 'Unknown');
        } elseif (isset($control->Type)) {
            $type = (string) $control->Type;
        }
        $path = $pathPrefix . '/' . $name;
        $children = [];
        if (isset($control->Children) && is_array($control->Children)) {
            foreach ($control->Children as &$child) {
                if (is_object($child)) {
                    $children[] = $this->walkJsonControlReturn($child, $path);
                }
            }
            unset($child);
        }
        $node = new ControlNode($name, $type, $path, 'json', $control, $children, $this->mutations);
        $this->controls[] = $node;
        return $node;
    }

    private static function looksLikeControlJson(object $data): bool
    {
        if (isset($data->TopParent) || isset($data->Controls)) {
            return true;
        }
        if (isset($data->Name) && (isset($data->Rules) || isset($data->Children) || isset($data->Template))) {
            return true;
        }
        return false;
    }

    private static function containsInvariantScript(object|array $data): bool
    {
        if (is_object($data)) {
            foreach (get_object_vars($data) as $key => $value) {
                if ($key === 'InvariantScript' && is_string($value)) {
                    return true;
                }
                if ((is_object($value) || is_array($value)) && self::containsInvariantScript($value)) {
                    return true;
                }
            }
            return false;
        }

        foreach ($data as $key => $value) {
            if ($key === 'InvariantScript' && is_string($value)) {
                return true;
            }
            if ((is_object($value) || is_array($value)) && self::containsInvariantScript($value)) {
                return true;
            }
        }
        return false;
    }

    /** @param array<mixed> $arr */
    private function isAssoc(array $arr): bool
    {
        return !array_is_list($arr);
    }
}
