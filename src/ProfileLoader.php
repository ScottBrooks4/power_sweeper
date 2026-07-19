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
}
