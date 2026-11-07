<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Infrastructure\MachineSoftware\MachineRecipe;
use App\Infrastructure\MachineSoftware\RecipeLoader;
use Illuminate\Support\Facades\DB;

/**
 * The desired machine recipe: the recipe.json of the release the Gateway serves, and its identity. The converge intents of a task VM
 * host and of a task start carry it, and the UpCloud template build records it as its desired identity. The Incus recipe image build is
 * retired (docs/reference/old-paths.md); a host's base is made only by the base snapshot refresh (docs/cli/tasks/vm-image-refresh.mmd).
 */
final readonly class RecipeImageBuilds
{
    public function __construct(private RecipeLoader $loader) {}

    public function currentRecipe(): MachineRecipe
    {
        $path = rtrim(config()->string('orbit.gateway_checkout'), '/').'/resources/machine-software/recipe.json';

        return $this->loader->load(is_link(dirname(config()->string('orbit.gateway_checkout'), 2)) ? $path : null);
    }

    /**
     * Makes `$recipe` the desired recipe and returns its generation; a recipe that is already desired keeps its generation.
     * The write first takes SQLite's writer fence, so a concurrent change waits.
     */
    public function desire(MachineRecipe $recipe): int
    {
        return DB::transaction(function () use ($recipe): int {
            DB::table('task_vm_image_recipe')->where('id', 1)->where('identity', '!=', $recipe->identity)
                ->orWhere(fn ($query) => $query->where('id', 1)->whereNull('identity'))
                ->update(['identity' => $recipe->identity, 'recipe_json' => $recipe->json, 'generation' => DB::raw('generation + 1')]);
            $state = DB::table('task_vm_image_recipe')->where('id', 1)->firstOrFail();

            return (int) $state->generation;
        });
    }
}
