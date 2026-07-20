<?php

declare(strict_types=1);

namespace PowerSweeper;

final class Report
{
    /** @var list<array{hop:string,control:string,property:string,from:string,to:string}> */
    private array $entries = [];

    /** @var null|callable(array{hop:string,control:string,property:string,from:string,to:string}, int):void */
    private $onChange;

    /** @param null|callable(array{hop:string,control:string,property:string,from:string,to:string}, int):void $onChange */
    public function __construct(?callable $onChange = null)
    {
        $this->onChange = $onChange;
    }

    public function add(string $hop, string $control, string $property, string $from, string $to): void
    {
        $entry = [
            'hop' => $hop,
            'control' => $control,
            'property' => $property,
            'from' => $from,
            'to' => $to,
        ];
        $this->entries[] = $entry;
        if ($this->onChange !== null) {
            ($this->onChange)($entry, $this->count());
        }
    }

    /** @return list<array{hop:string,control:string,property:string,from:string,to:string}> */
    public function entries(): array
    {
        return $this->entries;
    }

    public function count(): int
    {
        return count($this->entries);
    }

    /** @return array{total:int,by_hop:array<string,int>,entries:list<array{hop:string,control:string,property:string,from:string,to:string}>} */
    public function toArray(): array
    {
        $byHop = [];
        foreach ($this->entries as $entry) {
            $byHop[$entry['hop']] = ($byHop[$entry['hop']] ?? 0) + 1;
        }

        return [
            'total' => $this->count(),
            'by_hop' => $byHop,
            'entries' => $this->entries,
        ];
    }
}
