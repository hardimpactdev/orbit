<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\TaskVms\TaskVmError;
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
        $this->requireServingDelivery($build);
        $desired = DB::table('task_vm_image_recipe')->where('id', 1)->firstOrFail();
        if ($desired->identity !== $build->recipe_identity || (int) $desired->generation !== $build->recipe_generation) {
            throw new TaskVmException('task_vm.image_recipe_superseded', 'A newer recipe is desired. This image attempt cannot continue.');
        }
    }

    private function requireServingDelivery(TaskVmImageBuild $build): void
    {
        $recipe = new RecipeLoader()->decode((string) $build->recipe_json);
        if ($recipe->agentDigest((string) $build->hostNode->architecture) !== app(AgentDelivery::class)->pin($build->hostNode)) {
            throw new TaskVmException(TaskVmError::ImageRecipeSuperseded, 'The serving recipe delivery changed. This build cannot continue or promote.');
        }
    }
}
