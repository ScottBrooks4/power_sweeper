<?php

declare(strict_types=1);

namespace PowerSweeper;

/** Shared dirty flag for a ControlDocument and its ControlNode views. */
final class MutationTracker
{
    private bool $dirty = false;

    public function mark(): void
    {
        $this->dirty = true;
    }

    public function isDirty(): bool
    {
        return $this->dirty;
    }
}
