<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Infrastructure\MachineSoftware\MachineRecipe;
use App\Infrastructure\MachineSoftware\RecipeLoader;

/**
 * The desired machine recipe: the recipe.json of the release the Gateway serves, and its identity. The converge intents of a task VM
 * host and of a task start carry it. The Incus recipe image build is
 * retired (docs/reference/old-paths.md); a host's base is made only by the base snapshot refresh (docs/cli/task/vm-image-refresh.mmd).
 */
final readonly class RecipeImageBuilds
{
    public function __construct(private RecipeLoader $loader) {}

    public function currentRecipe(): MachineRecipe
    {
        $path = rtrim(config()->string('orbit.gateway_checkout'), '/').'/resources/machine-software/recipe.json';

        return $this->loader->load(is_link(dirname(config()->string('orbit.gateway_checkout'), 2)) ? $path : null);
    }
}
