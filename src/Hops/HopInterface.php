<?php

declare(strict_types=1);

namespace PowerSweeper\Hops;

use PowerSweeper\ControlDocument;
use PowerSweeper\Report;

interface HopInterface
{
    public static function id(): string;

    public static function label(): string;

    public static function description(): string;

    /**
     * @param list<ControlDocument> $documents
     * @param array<string, mixed> $options
     */
    public function apply(array $documents, Report $report, array $options = []): void;
}
