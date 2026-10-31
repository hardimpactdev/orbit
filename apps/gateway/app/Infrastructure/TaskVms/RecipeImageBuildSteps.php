<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\TaskVms\TaskVmException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\MachineSoftware\RecipeLoader;
use App\Models\TaskVmImageBuild;
use Illuminate\Support\Facades\DB;

/** Pure desired-recipe and serving-delivery checks for admitted image intent. */
final readonly class RecipeImageBuildSteps
{
    public function assertCurrent(TaskVmImageBuild $build): void
    {
        if (! $this->current($build)) {
            throw new TaskVmException('task_vm.image_recipe_superseded', 'The desired recipe or serving delivery changed. This image attempt cannot continue.');
        }
    }

    public function current(TaskVmImageBuild $build): bool
    {
        $recipe = new RecipeLoader()->decode((string) $build->recipe_json);
        $desired = DB::table('task_vm_image_recipe')->where('id', 1)->firstOrFail();

        return $desired->identity === $build->recipe_identity
            && (int) $desired->generation === $build->recipe_generation
            && $recipe->agentDigest((string) $build->hostNode->architecture) === app(AgentDelivery::class)->pin($build->hostNode);
    }
}
