<?php

declare(strict_types=1);

namespace Tests\Support\Fleet;

use App\Infrastructure\MachineSoftware\MachineRecipe;
use App\Infrastructure\MachineSoftware\RecipeLoader;
use App\Infrastructure\MachineSoftware\SnapshotAvailability;

final readonly class FakeUbuntuSnapshot implements SnapshotAvailability
{
    public function __construct(private string $suffix = '') {}

    public function verify(MachineRecipe $recipe): void {}

    public function latest(string $snapshot, array $architectures, array $packages): array
    {
        $recipe = new RecipeLoader()->load();
        $versions = [];
        foreach ($packages as $name) {
            $versions[$name] = $recipe->tools[$name]->version.$this->suffix;
        }

        return $versions;
    }
}
