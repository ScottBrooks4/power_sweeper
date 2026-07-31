<?php

declare(strict_types=1);

namespace PowerSweeper;

/**
 * Runtime context for live App checker: datasources, package record fields, and
 * which document is authoritative per screen (Controls JSON over Src YAML).
 */
final class AppDataContext
{
    /** @var array<string, true> */
    private array $dataSourceNames = [];

    /** @var array<string, true> */
    private array $packageFields = [];

    /** @var array<string, string> screen name => preferred document relative path */
    private array $preferredDocByScreen = [];

    /** @var array<string, true> Power Fx enum type namespaces (LayoutMode, Font, Color, …) */
    private const ENUM_TYPES = [
        'LayoutMode' => true, 'Align' => true, 'Font' => true, 'Color' => true,
        'BorderStyle' => true, 'DropShadow' => true, 'DisplayMode' => true, 'FormMode' => true,
        'Layout' => true, 'Transition' => true, 'TransitionDirection' => true, 'TransitionType' => true,
        'NotificationType' => true, 'ErrorKind' => true, 'SortOrder' => true, 'JSONFormat' => true,
        'TextFormat' => true, 'DateTimeFormat' => true, 'ScreenSize' => true, 'ScreenOrientation' => true,
        'Size' => true, 'ImagePosition' => true, 'ImageRotation' => true, 'Overflow' => true,
        'Reset' => true, 'StartOfWeek' => true, 'Icon' => true, 'Badge' => true, 'BadgePosition' => true,
        'InputTextMode' => true, 'VerticalAlign' => true, 'FontWeight' => true, 'LayoutDirection' => true,
        'LayoutAlignItems' => true, 'LayoutJustifyContent' => true, 'LayoutGap' => true, 'LayoutOverflow' => true,
        'AlignInContainer' => true, 'TextRole' => true, 'Live' => true, 'ValidationState' => true,
        'Appearance' => true, 'TextInputType' => true, 'TriggerOutput' => true, 'FormPage' => true,
        'ButtonCanvas' => true, 'Tracking' => true, 'List' => true, 'ButtonAppearance' => true,
        'ButtonLayout' => true, 'IconStyle' => true, 'ScreenTransition' => true, 'TabIndex' => true,
        'HoverBorderColor' => true, 'PressedBorderColor' => true, 'FocusedBorderColor' => true,
        'DisabledBorderColor' => true, 'BorderColor' => true, 'Fill' => true, 'HoverFill' => true,
        'PressedFill' => true, 'DisabledFill' => true, 'Color' => true, 'HoverColor' => true,
        'PressedColor' => true, 'DisabledColor' => true, 'FocusedBorderThickness' => true,
        'Microsoft' => true, 'Azure' => true, 'SharePoint' => true, 'PowerApps' => true,
        'GroupContainer' => true, 'Classic' => true, 'Modern' => true, 'Canvas' => true,
        'VCR' => true, 'Approval' => true, 'DefaultGrayBackgroud' => true,
    ];

    /** @var array<string, true> */
    private const ENUM_NAMES = [
        'Center' => true, 'Left' => true, 'Right' => true, 'Top' => true, 'Bottom' => true,
        'Middle' => true, 'Inside' => true, 'Outside' => true, 'Stretch' => true,
        'LayoutDirection' => true, 'LayoutAlignItems' => true, 'LayoutJustifyContent' => true,
        'LayoutGap' => true, 'LayoutOverflow' => true, 'AlignInContainer' => true,
        'VerticalAlign' => true, 'FontWeight' => true, 'Font' => true, 'BorderStyle' => true,
        'DropShadow' => true, 'DisplayMode' => true, 'FormMode' => true, 'Layout' => true,
        'Transition' => true, 'TransitionDirection' => true, 'TransitionType' => true,
        'NotificationType' => true, 'ErrorKind' => true, 'SortOrder' => true,
        'JSONFormat' => true, 'TextFormat' => true, 'DateTimeFormat' => true,
        'ScreenSize' => true, 'ScreenOrientation' => true, 'Size' => true,
        'Color' => true, 'ImagePosition' => true, 'ImageRotation' => true,
        'Overflow' => true, 'Reset' => true, 'StartOfWeek' => true,
        'Control' => true, 'Icon' => true, 'Badge' => true, 'BadgePosition' => true,
        'InputTextMode' => true, 'OnSelect' => true, 'OnChange' => true,
        'OnVisible' => true, 'OnHidden' => true, 'OnCheck' => true, 'OnUncheck' => true,
        'OnSelect' => true, 'OnSuccess' => true, 'OnFailure' => true,
        'Microsoft' => true, 'Azure' => true, 'SharePoint' => true,
        'Value' => true, // table column / enum in CountIf(App.SizeBreakpoints, Value >= …)
        'Error' => true, 'Warning' => true, 'Information' => true, 'Success' => true,
        'Hour' => true, 'Minute' => true, 'Second' => true,
        'None' => true, 'Auto' => true, 'Manual' => true, 'Disabled' => true, 'Edit' => true, 'New' => true, 'View' => true,
        'Small' => true, 'Medium' => true, 'Large' => true, 'ExtraLarge' => true,
        'Bold' => true, 'Lighter' => true, 'Normal' => true, 'Semibold' => true,
        'Start' => true, 'End' => true, 'SpaceBetween' => true, 'SpaceAround' => true, 'SpaceEvenly' => true,
        'Wrap' => true, 'Scroll' => true, 'Hide' => true, 'Show' => true,
        'Accept' => true, 'Cancel' => true, 'Ignore' => true,
        'Text' => true, 'HtmlText' => true, 'Selected' => true, 'SelectedDate' => true,
        'Checked' => true, 'Pressed' => true, 'Hover' => true, 'Focused' => true,
        'Width' => true, 'Height' => true, 'X' => true, 'Y' => true,
        'Fill' => true, 'Color' => true, 'BorderColor' => true, 'DisabledFill' => true,
        'DisabledColor' => true, 'HoverFill' => true, 'HoverColor' => true,
        'PressedFill' => true, 'PressedColor' => true, 'FocusedBorderColor' => true,
        'Visible' => true, 'DisplayMode' => true, 'Default' => true,
        'Email' => true, 'Claims' => true, 'DisplayName' => true, 'Department' => true,
        'JobTitle' => true, 'Picture' => true, 'odata' => true,
        'Blank' => true, 'Empty' => true, 'Null' => true,
        'Open' => true, 'Sans' => true, 'Segoe' => true, 'UI' => true, 'Semibold' => true,
        'Off' => true, 'On' => true, 'Primary' => true, 'Secondary' => true, 'Subtle' => true,
        'Title' => true, 'Subtitle' => true, 'Body' => true, 'Caption' => true,
        'AgencyName' => true, 'ParticularSurname' => true, 'RequestID' => true,
        'AgencyMilitary' => true, 'Hour' => true, 'Minute' => true,
    ];

    /**
     * @param list<ControlDocument> $documents
     */
    public static function build(array $documents, ?string $extractDir = null): self
    {
        $ctx = new self();
        if ($extractDir !== null) {
            $ctx->loadDataSources($extractDir);
        }
        $ctx->packageFields = self::collectPackageFields($documents);
        $ctx->preferredDocByScreen = self::resolvePreferredDocs($documents);
        return $ctx;
    }

    /**
     * @param list<ControlDocument> $documents
     * @return list<ControlDocument> documents to scan (deduped per screen)
     */
    public function documentsToScan(array $documents): array
    {
        $preferred = array_flip($this->preferredDocByScreen);
        $out = [];
        foreach ($documents as $doc) {
            $path = $doc->relativePath;
            if (isset($preferred[$path])) {
                $out[] = $doc;
                continue;
            }
            // Always include App, components, and docs without a JSON twin.
            if (str_starts_with($path, 'Src/App.') || str_contains($path, 'Components/')) {
                $out[] = $doc;
                continue;
            }
            if ($doc->format === 'json' && str_starts_with($path, 'Controls/')) {
                $out[] = $doc;
                continue;
            }
            if ($doc->format === 'yaml' && str_starts_with($path, 'Src/')) {
                $screen = $doc->screenName();
                if ($screen === null || !isset($this->preferredDocByScreen[$screen])) {
                    $out[] = $doc;
                }
            }
        }
        return $out;
    }

    public function isDataSource(string $name): bool
    {
        return isset($this->dataSourceNames[$name]);
    }

    public function isPackageField(string $name): bool
    {
        return isset($this->packageFields[$name]);
    }

    /** @return array<string, true> */
    public function packageFields(): array
    {
        return $this->packageFields;
    }

    public function isEnumType(string $name): bool
    {
        return isset(self::ENUM_TYPES[$name]);
    }

    public function isEnumOrBuiltin(string $name): bool
    {
        return isset(self::ENUM_NAMES[$name]) || isset(self::ENUM_TYPES[$name]);
    }

    private function loadDataSources(string $extractDir): void
    {
        $candidates = [
            $extractDir . '/References/DataSources.json',
            $extractDir . '/References\\DataSources.json',
        ];
        foreach ($candidates as $path) {
            if (!is_file($path)) {
                continue;
            }
            $raw = file_get_contents($path);
            if ($raw === false) {
                continue;
            }
            $data = json_decode($raw, true);
            if (!is_array($data)) {
                continue;
            }
            $list = $data['DataSources'] ?? ($data[0]['DataSources'] ?? []);
            if (!is_array($list)) {
                continue;
            }
            foreach ($list as $ds) {
                if (!is_array($ds)) {
                    continue;
                }
                $name = (string) ($ds['Name'] ?? '');
                if ($name !== '') {
                    $this->dataSourceNames[$name] = true;
                }
            }
            break;
        }

        // Quoted SharePoint / SQL list names appear in formulas — index display names.
        foreach (array_keys($this->dataSourceNames) as $name) {
            if (str_contains($name, ' ')) {
                $this->dataSourceNames[str_replace("''", "'", $name)] = true;
            }
        }
    }

    /**
     * @param list<ControlDocument> $documents
     * @return array<string, true>
     */
    private static function collectPackageFields(array $documents): array
    {
        $fields = [];
        foreach ($documents as $doc) {
            foreach ($doc->controls() as $control) {
                if ($control->name !== 'ExternalFunctions') {
                    continue;
                }
                $load = $control->getProperty('loadPackage');
                if ($load === null || $load === '') {
                    continue;
                }
                foreach (explode("\n", $load) as $line) {
                    $t = trim($line);
                    if ($t === '' || str_starts_with($t, '//') || str_starts_with($t, '/*')) {
                        continue;
                    }
                    if (preg_match('/^([A-Za-z_][\w]*)\s*:/', $t, $m)) {
                        $fields[$m[1]] = true;
                    }
                }
            }
        }
        return $fields;
    }

    /**
     * @param list<ControlDocument> $documents
     * @return array<string, string>
     */
    private static function resolvePreferredDocs(array $documents): array
    {
        $byScreen = [];
        foreach ($documents as $doc) {
            $screen = $doc->screenName();
            if ($screen === null) {
                continue;
            }
            if ($doc->format === 'json' && str_starts_with($doc->relativePath, 'Controls/')) {
                $byScreen[$screen] = $doc->relativePath;
            } elseif (!isset($byScreen[$screen]) && $doc->format === 'yaml' && str_starts_with($doc->relativePath, 'Src/')) {
                $byScreen[$screen] = $doc->relativePath;
            }
        }
        return $byScreen;
    }
}
