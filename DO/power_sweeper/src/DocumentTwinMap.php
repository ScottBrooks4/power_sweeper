<?php

declare(strict_types=1);

namespace PowerSweeper;

/**
 * Pair Src YAML screens/components with their Controls/Components JSON twins.
 *
 * Large apps keep the same formulas in both trees. Sweeping YAML then copying
 * properties into JSON avoids ~80% of formula walks on THCEE/EHCR packs.
 */
final class DocumentTwinMap
{
    /** @var array<string, ControlDocument> yaml key → yaml doc */
    private array $yamlByKey = [];

    /** @var array<string, ControlDocument> same key → json twin */
    private array $jsonByKey = [];

    /** @var array<string, true> relativePath of JSON docs that have a YAML twin */
    private array $jsonTwinPaths = [];

    /**
     * @param list<ControlDocument> $documents
     */
    public function __construct(array $documents)
    {
        foreach ($documents as $doc) {
            $key = $this->pairKey($doc);
            if ($key === null) {
                continue;
            }
            if ($doc->format === 'yaml') {
                $this->yamlByKey[$key] = $doc;
            } elseif ($doc->format === 'json') {
                $this->jsonByKey[$key] = $doc;
            }
        }
        foreach ($this->jsonByKey as $key => $json) {
            if (isset($this->yamlByKey[$key])) {
                $this->jsonTwinPaths[$json->relativePath] = true;
            }
        }
    }

    public function isJsonTwin(ControlDocument $doc): bool
    {
        return isset($this->jsonTwinPaths[$doc->relativePath]);
    }

    /**
     * Copy control types + properties from each YAML doc onto its JSON twin.
     *
     * @return int number of properties written
     */
    public function syncYamlToJson(): int
    {
        $written = 0;
        foreach ($this->yamlByKey as $key => $yaml) {
            $json = $this->jsonByKey[$key] ?? null;
            if ($json === null) {
                continue;
            }
            $jsonByName = [];
            foreach ($json->controls() as $control) {
                $jsonByName[$control->name] = $control;
            }
            foreach ($yaml->controls() as $src) {
                $dst = $jsonByName[$src->name] ?? null;
                if ($dst === null) {
                    continue;
                }
                if ($src->type !== '' && $src->type !== $dst->type
                    && (str_starts_with($src->type, 'Classic/') || str_contains(strtolower($src->type), 'appmagic'))
                ) {
                    $dst->setControlType($src->type, $this->jsonTemplateFromYamlType($src->type), true);
                }
                foreach ($src->propertyNames() as $prop) {
                    $value = $src->getProperty($prop);
                    if ($value === null) {
                        continue;
                    }
                    $dst->setProperty($prop, $value);
                    $written++;
                }
            }
            if ($written > 0) {
                $json->markDirty();
            }
        }

        return $written;
    }

    private function pairKey(ControlDocument $doc): ?string
    {
        $path = str_replace('\\', '/', $doc->relativePath);
        if ($doc->format === 'yaml') {
            if (str_starts_with($path, 'Src/App.')) {
                return 'screen:App';
            }
            if (str_starts_with($path, 'Src/Components/')) {
                $base = basename($path, '.pa.yaml');

                return $base !== '' ? 'component:' . $base : null;
            }
            if (str_starts_with($path, 'Src/') && str_ends_with($path, '.pa.yaml')) {
                $screen = $doc->screenName();

                return is_string($screen) && $screen !== '' ? 'screen:' . $screen : null;
            }

            return null;
        }

        if ($doc->format === 'json') {
            if (str_starts_with($path, 'Controls/')) {
                $screen = $doc->screenName();

                return is_string($screen) && $screen !== '' ? 'screen:' . $screen : null;
            }
            if (str_starts_with($path, 'Components/')) {
                // TopParent.Name is the component definition name (not the first child).
                $data = $doc->rawData();
                if (is_object($data) && isset($data->TopParent) && is_object($data->TopParent)) {
                    $name = (string) ($data->TopParent->Name ?? '');
                    if ($name !== '') {
                        return 'component:' . $name;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Map YAML Control: types onto Studio JSON Template Id/Name.
     * Must not naively lowercase Classic/TextInput → "textinput" — Studio's
     * classic text-input widget is named "text" (http://microsoft.com/appmagic/text).
     *
     * @return array{Id?:string,Name?:string,Version?:string}
     */
    private function jsonTemplateFromYamlType(string $yamlType): array
    {
        $fromMap = self::jsonTemplateFromThemeMap($yamlType);
        if ($fromMap !== []) {
            return $fromMap;
        }

        if (preg_match('#^Classic/([A-Za-z]+)@([\d.]+)$#', $yamlType, $m) === 1) {
            $stem = $m[1];
            // Studio JSON names that differ from strtolower(Classic/Stem).
            $name = match (strtolower($stem)) {
                'textinput' => 'text',
                default => strtolower($stem),
            };

            return [
                'Id' => 'http://microsoft.com/appmagic/' . $name,
                'Name' => $name,
                'Version' => $m[2],
            ];
        }

        return [];
    }

    /**
     * @return array{Id?:string,Name?:string,Version?:string}
     */
    private static function jsonTemplateFromThemeMap(string $yamlType): array
    {
        static $byYaml = null;
        if ($byYaml === null) {
            $byYaml = [];
            $file = dirname(__DIR__) . '/config/theme_control_map.php';
            if (is_file($file)) {
                /** @var mixed $loaded */
                $loaded = include $file;
                if (is_array($loaded)) {
                    foreach ($loaded as $entry) {
                        if (!is_array($entry)) {
                            continue;
                        }
                        $yaml = (string) ($entry['yaml'] ?? '');
                        $json = $entry['json'] ?? null;
                        if ($yaml === '' || !is_array($json)) {
                            continue;
                        }
                        $id = (string) ($json['Id'] ?? '');
                        $name = (string) ($json['Name'] ?? '');
                        if ($id === '' || $name === '') {
                            continue;
                        }
                        $byYaml[strtolower($yaml)] = [
                            'Id' => $id,
                            'Name' => $name,
                            'Version' => (string) ($json['Version'] ?? ''),
                        ];
                    }
                }
            }
        }

        return $byYaml[strtolower($yamlType)] ?? [];
    }
}
