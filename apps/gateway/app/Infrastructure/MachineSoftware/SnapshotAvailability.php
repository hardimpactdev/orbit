<?php

declare(strict_types=1);

namespace App\Infrastructure\MachineSoftware;

interface SnapshotAvailability
{
    /** Verify signed snapshot metadata and every newest Ubuntu package pin across all architectures. */
    public function verify(MachineRecipe $recipe): void;

    /**
     * @param  list<string>  $architectures
     * @param  list<string>  $packages
     * @return array<string, string>
     */
    public function latest(string $snapshot, array $architectures, array $packages): array;
}
