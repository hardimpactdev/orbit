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
        return $this->loader->load($this->path());
    }

    public function source(): MachineRecipe
    {
        return $this->loader->source($this->path());
    }

    private function path(): ?string
    {
        $checkout = rtrim(config()->string('orbit.gateway_checkout'), '/');

        return is_link(dirname($checkout, 2)) ? $checkout.'/resources/machine-software/recipe.json' : null;
    }
}
