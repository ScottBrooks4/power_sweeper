<?php

declare(strict_types=1);

namespace PowerSweeper;

/**
 * Change log for hop runs.
 *
 * Large apps (THCEE dark mode / formula converge) can produce 10k–30k+ rows.
 * Keeping every from/to string in RAM OOMs shared hosts, so we:
 * - track accurate totals / by_hop counts always
 * - retain only the first {@see $maxEntries} detail rows (UI preview)
 * - truncate long from/to snippets when storing or streaming
 */
final class Report
{
    /** @var list<array{hop:string,control:string,property:string,from:string,to:string}> */
    private array $entries = [];

    /** @var array<string, int> */
    private array $byHop = [];

    private int $total = 0;

    /** @var null|callable(array{hop:string,control:string,property:string,from:string,to:string}, int):void */
    private $onChange;

    private int $maxEntries;

    private int $snippetChars;

    /**
     * @param null|callable(array{hop:string,control:string,property:string,from:string,to:string}, int):void $onChange
     */
    public function __construct(?callable $onChange = null, int $maxEntries = 500, int $snippetChars = 240)
    {
        $this->onChange = $onChange;
        $this->maxEntries = max(0, $maxEntries);
        $this->snippetChars = max(16, $snippetChars);
    }

    public function add(string $hop, string $control, string $property, string $from, string $to): void
    {
        $entry = [
            'hop' => $hop,
            'control' => $control,
            'property' => $property,
            'from' => $this->snippet($from),
            'to' => $this->snippet($to),
        ];
        $this->total++;
        $this->byHop[$hop] = ($this->byHop[$hop] ?? 0) + 1;
        if (count($this->entries) < $this->maxEntries) {
            $this->entries[] = $entry;
        }
        if ($this->onChange !== null) {
            ($this->onChange)($entry, $this->total);
        }
    }

    /** @return list<array{hop:string,control:string,property:string,from:string,to:string}> */
    public function entries(): array
    {
        return $this->entries;
    }

    public function count(): int
    {
        return $this->total;
    }

    /** @return array{total:int,by_hop:array<string,int>,entries:list<array{hop:string,control:string,property:string,from:string,to:string}>,entries_truncated?:bool,entries_omitted?:int} */
    public function toArray(): array
    {
        $out = [
            'total' => $this->total,
            'by_hop' => $this->byHop,
            'entries' => $this->entries,
        ];
        $kept = count($this->entries);
        if ($this->total > $kept) {
            $out['entries_truncated'] = true;
            $out['entries_omitted'] = $this->total - $kept;
        }

        return $out;
    }

    private function snippet(string $value): string
    {
        if (strlen($value) <= $this->snippetChars) {
            return $value;
        }

        return substr($value, 0, max(1, $this->snippetChars - 3)) . '...';
    }
}
