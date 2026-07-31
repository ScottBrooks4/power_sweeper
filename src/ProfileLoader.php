<?php

declare(strict_types=1);

namespace PowerSweeper;

final class ProfileLoader
{
    public function __construct(private readonly string $profilesDir)
    {
    }

    /** @return list<array{id:string,description:string,hops:list<array{id:string,options?:array<string,mixed>}>}> */
    public function all(): array
    {
        if (!is_dir($this->profilesDir)) {
            return [];
        }

        $out = [];
        foreach (scandir($this->profilesDir) ?: [] as $file) {
            if (!str_ends_with($file, '.php')) {
                continue;
            }
            $full = $this->profilesDir . DIRECTORY_SEPARATOR . $file;
            if (is_dir($full)) {
                continue;
            }
            $id = basename($file, '.php');
            $config = include $this->profilesDir . DIRECTORY_SEPARATOR . $file;
            if (!is_array($config)) {
                continue;
            }
            $out[] = [
                'id' => $id,
                'description' => (string) ($config['description'] ?? ''),
                'hops' => $config['hops'] ?? [],
            ];
        }

        usort($out, static fn($a, $b) => $a['id'] <=> $b['id']);
        return $out;
    }

    /** @return array{description?:string,hops:list<array{id:string,options?:array<string,mixed>}>}|null */
    public function loadByPath(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $config = include $path;
        if (!is_array($config) || !isset($config['hops']) || !is_array($config['hops'])) {
            return null;
        }

        return $config;
    }

    /**
     * Pick a powered deliverable profile for an input msapp.
     *
     * @return array{description?:string,hops:list<array{id:string,options?:array<string,mixed>}>}
     */
    public function resolvePoweredProfile(string $inputMsapp, ?string $explicitProfilePath = null): array
    {
        if ($explicitProfilePath !== null) {
            $config = $this->loadByPath($explicitProfilePath);
            if ($config !== null) {
                return $config;
            }
        }

        $basename = basename($inputMsapp);
        if (preg_match('/THCEE/i', $basename)) {
            $config = $this->loadByPath($this->profilesDir . '/powered_thcee.php');
            if ($config !== null) {
                return $config;
            }
        }

        $config = $this->loadByPath($this->profilesDir . '/repair_powered.php');
        if ($config !== null) {
            return $config;
        }

        throw new \RuntimeException('No powered profile found in ' . $this->profilesDir);
    }
}
