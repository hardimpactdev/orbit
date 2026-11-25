<?php

declare(strict_types=1);

namespace App\Infrastructure\MachineSoftware;

/**
 * The machine recipe of the release the Gateway serves, used by Instance and container-host intents.
 */
final readonly class CurrentRecipe
{
    public function __construct(private RecipeLoader $loader) {}

    public function currentRecipe(): MachineRecipe
    {
        $path = rtrim(config()->string('orbit.gateway_checkout'), '/').'/resources/machine-software/core.yaml';

        return $this->loader->load(is_link(dirname(config()->string('orbit.gateway_checkout'), 2)) ? $path : null);
    }
}
