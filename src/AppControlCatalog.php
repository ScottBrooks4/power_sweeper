<?php

declare(strict_types=1);

namespace PowerSweeper;

/**
 * App-wide index of screen names, control names, and component instance children.
 * Used to repair stale suffixed refs and cross-screen copy-paste mistakes.
 */
final class AppControlCatalog
{
    /** @var array<string, array<string, true>> screen display name => control names in scope */
    private array $controlsByScreen = [];

    /** @var array<string, list<string>> control name => screens where it appears */
    private array $screensByControl = [];

    /**
     * screen => instanceName => list of child control names inside the component instance
     *
     * @var array<string, array<string, list<string>>>
     */
    private array $componentChildrenByScreen = [];

    /** @var array<string, string> component definition name => definition document path */
    private array $componentDefPaths = [];

    /** @var array<string, list<string>> component definition name => child control names */
    private array $componentDefChildren = [];

    /** @var array<string, string> document relative path => screen display name */
    private array $screenByDocPath = [];

    /** @var array<string, string> */
    private const RESERVED = [
        'true' => true, 'false' => true, 'Blank' => true, 'Self' => true, 'Parent' => true,
        'ThisItem' => true, 'User' => true, 'App' => true, 'Param' => true, 'If' => true,
        'Switch' => true, 'With' => true, 'Set' => true, 'UpdateContext' => true, 'Navigate' => true,
        'Collect' => true, 'ClearCollect' => true, 'Patch' => true, 'LookUp' => true, 'Filter' => true,
        'Search' => true, 'CountIf' => true, 'Sum' => true, 'ForAll' => true, 'Concurrent' => true,
        'Notify' => true, 'Reset' => true, 'Refresh' => true, 'SubmitForm' => true, 'Back' => true,
        'Color' => true, 'RGBA' => true, 'Text' => true, 'Value' => true, 'DateTime' => true,
        'Now' => true, 'Today' => true, 'Lower' => true, 'Upper' => true, 'Trim' => true,
        'Coalesce' => true, 'IsBlank' => true, 'Len' => true, 'Concat' => true, 'JSON' => true,
        'Table' => true, 'Record' => true, 'Error' => true, 'GUID' => true, 'Rand' => true,
    ];

    /**
     * @param list<ControlDocument> $documents
     */
    public static function build(array $documents): self
    {
        $cat = new self();

        foreach ($documents as $doc) {
            if (str_starts_with($doc->relativePath, 'Src/Components/')) {
                $defName = basename($doc->relativePath, '.pa.yaml');
                $cat->componentDefPaths[$defName] = $doc->relativePath;
                $children = [];
                foreach ($doc->controls() as $control) {
                    $children[$control->name] = true;
                }
                $cat->componentDefChildren[$defName] = array_keys($children);
            }
        }

        foreach ($documents as $doc) {
            $screen = $cat->inferScreenName($doc);
            if ($screen === null) {
                continue;
            }
            $cat->screenByDocPath[$doc->relativePath] = $screen;
            if (!isset($cat->controlsByScreen[$screen])) {
                $cat->controlsByScreen[$screen] = [];
            }

            foreach ($doc->controls() as $control) {
                $cat->controlsByScreen[$screen][$control->name] = true;
                $cat->screensByControl[$control->name][] = $screen;

                if (str_contains($control->type, 'CanvasComponent')) {
                    $def = $cat->componentNameFromInstance($control->name);
                    $children = $cat->componentDefChildren[$def] ?? [];
                    if ($children !== []) {
                        $cat->componentChildrenByScreen[$screen][$control->name] = $children;
                        foreach ($children as $child) {
                            // Component children are addressable as Instance.Child when instance is on screen.
                            $cat->controlsByScreen[$screen][$child] = true;
                        }
                    }
                }
            }
        }

        foreach ($cat->screensByControl as $name => $screens) {
            $cat->screensByControl[$name] = array_values(array_unique($screens));
        }

        return $cat;
    }

    public function screenForDocument(ControlDocument $doc): ?string
    {
        return $this->screenByDocPath[$doc->relativePath] ?? $this->inferScreenName($doc);
    }

    public function hasOnScreen(string $screen, string $name): bool
    {
        return isset($this->controlsByScreen[$screen][$name]);
    }

    /**
     * @return list<string>
     */
    public function screensWith(string $name): array
    {
        return $this->screensByControl[$name] ?? [];
    }

    public function quoteScreen(string $screen): string
    {
        if (preg_match('/^[A-Za-z_][\w]*$/', $screen)) {
            return $screen;
        }
        return "'" . str_replace("'", "''", $screen) . "'";
    }

    public function qualify(string $screen, string $control): string
    {
        $screenPart = $this->quoteScreen($screen);
        if (preg_match('/^[A-Za-z_][\w]*$/', $control)) {
            return $screenPart . '.' . $control;
        }
        return $screenPart . ".'" . str_replace("'", "''", $control) . "'";
    }

    /**
     * Resolve a bare identifier used on $screen to a replacement, or null if already valid / unknown.
     */
    public function resolveIdentifier(string $screen, string $identifier): ?string
    {
        if ($identifier === '' || isset(self::RESERVED[$identifier])) {
            return null;
        }
        if ($this->hasOnScreen($screen, $identifier)) {
            return null;
        }

        // Stale Studio duplicate suffix: Foo_1 when Foo exists on this screen.
        if (preg_match('/^(.+)_(\d+)$/', $identifier, $m)) {
            $base = $m[1];
            $suffix = (int) $m[2];
            if ($this->hasOnScreen($screen, $base)) {
                return $base;
            }
            if ($this->hasOnScreen($screen, $identifier)) {
                return null;
            }
            $others = array_values(array_filter(
                $this->screensWith($base),
                static fn(string $s): bool => $s !== $screen
            ));
            if (count($others) === 1) {
                return $this->qualify($others[0], $base);
            }

            // Same component type, different duplicate suffix on another screen (Agency_2 -> 'VCR / VCN Form'.Agency_1).
            $crossSuffix = $this->resolveCrossScreenSuffix($screen, $base, $suffix);
            if ($crossSuffix !== null) {
                return $crossSuffix;
            }

            // Child inside a component instance on this screen (NavTabs -> topNav_1.NavTabs_2).
            $componentRef = $this->resolveInComponentInstances($screen, $identifier, $base, $suffix);
            if ($componentRef !== null) {
                return $componentRef;
            }
        }

        // Bare name missing on screen but exists inside a single component instance child match.
        $componentRef = $this->resolveInComponentInstances($screen, $identifier, $identifier, null);
        if ($componentRef !== null) {
            return $componentRef;
        }

        // Cross-screen bare identifier (NavTabs on VCN Form -> topNav_1.NavTabs_2 handled above).
        $others = array_values(array_filter(
            $this->screensWith($identifier),
            static fn(string $s): bool => $s !== $screen
        ));
        if (count($others) === 1) {
            return $this->qualify($others[0], $identifier);
        }

        // Fuzzy: strip trailing punctuation (PertinenceSpecification-).
        if (preg_match('/^(.+)[\-_]$/', $identifier, $m)) {
            $trimmed = $m[1];
            if ($this->hasOnScreen($screen, $trimmed)) {
                return $trimmed;
            }
        }

        return null;
    }

    private function resolveCrossScreenSuffix(string $screen, string $base, int $suffix): ?string
    {
        $candidates = [];
        foreach ($this->screensByControl as $name => $screens) {
            if (!is_string($name)) {
                continue;
            }
            if (!preg_match('/^' . preg_quote($base, '/') . '_(\d+)$/', $name, $m)) {
                continue;
            }
            foreach ($screens as $otherScreen) {
                if ($otherScreen === $screen) {
                    continue;
                }
                $candidates[$otherScreen . '|' . $name] = (int) $m[1];
            }
        }
        if ($candidates === []) {
            return null;
        }
        // Prefer closest suffix number, then alphabetically for stability.
        uksort($candidates, static function (string $a, string $b) use ($candidates, $suffix): int {
            $da = abs($candidates[$a] - $suffix);
            $db = abs($candidates[$b] - $suffix);
            return $da !== $db ? $da <=> $db : $a <=> $b;
        });
        $best = array_key_first($candidates);
        [$otherScreen, $control] = explode('|', $best, 2);
        return $this->qualify($otherScreen, $control);
    }

    private function resolveInComponentInstances(string $screen, string $identifier, string $base, ?int $suffix): ?string
    {
        $instances = $this->componentChildrenByScreen[$screen] ?? [];
        $candidates = [];
        foreach ($instances as $instanceName => $children) {
            foreach ($children as $child) {
                $childBase = preg_replace('/_\d+$/', '', $child) ?? $child;
                if ($child === $identifier) {
                    return $instanceName . '.' . $child;
                }
                if ($childBase === $base || $childBase === $identifier) {
                    if ($suffix === null || str_ends_with($child, '_' . $suffix)) {
                        $candidates[$instanceName . '.' . $child] = true;
                    }
                }
                // NavTabs -> NavTabs_2 inside topNav_1
                if ($childBase === $base && preg_match('/^' . preg_quote($base, '/') . '_\d+$/', $child)) {
                    $candidates[$instanceName . '.' . $child] = true;
                }
            }
        }
        if (count($candidates) === 1) {
            return array_key_first($candidates);
        }
        return null;
    }

    private function inferScreenName(ControlDocument $doc): ?string
    {
        return $doc->screenName();
    }

    private function componentNameFromInstance(string $instanceName): string
    {
        if (preg_match('/^(.+)_\d+$/', $instanceName, $m)) {
            return $m[1];
        }
        return $instanceName;
    }

    public function isReserved(string $identifier): bool
    {
        return isset(self::RESERVED[$identifier]);
    }
}
