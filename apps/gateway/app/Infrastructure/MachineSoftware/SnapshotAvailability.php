<?php

declare(strict_types=1);

namespace App\Infrastructure\MachineSoftware;

interface SnapshotAvailability
{
    /** Verify signed snapshot metadata and every exact Ubuntu package version across all architectures. */
    public function verify(MachineRecipe $recipe): void;
}
