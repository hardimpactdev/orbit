<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\TaskVms\TaskVmException;
use App\Models\TaskVmImageBuild;
use Illuminate\Support\Facades\DB;

/**
 * Old path: do not extend; target: a refresh of the topology seed by converge, docs/reference/pipeline/6-task-image-seed-refresh.mmd. Still used until that refresh exists. Listed in docs/reference/old-paths.md.
 *
 * Pure desired-recipe check for admitted image intent.
 */
final readonly class RecipeImageBuildSteps
{
    public function assertCurrent(TaskVmImageBuild $build): void
    {
        if (! $this->current($build)) {
            throw new TaskVmException('task_vm.image_recipe_superseded', 'The desired recipe changed. This image attempt cannot continue.');
        }
    }

    public function current(TaskVmImageBuild $build): bool
    {
        $desired = DB::table('task_vm_image_recipe')->where('id', 1)->firstOrFail();

        return $desired->identity === $build->recipe_identity && (int) $desired->generation === $build->recipe_generation;
    }
}
