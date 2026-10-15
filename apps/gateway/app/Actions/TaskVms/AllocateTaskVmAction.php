<?php

declare(strict_types=1);

namespace App\Actions\TaskVms;

use App\Domain\Tasks\TaskCapacityException;
use App\Domain\Tasks\TaskWait;
use App\Domain\TaskVms\TaskVmCapacity;
use App\Domain\TaskVms\TaskVmImageBuildState;
use App\Domain\TaskVms\TaskVmPlacementPolicy;
use App\Domain\TaskVms\TaskVmProviderKind;
use App\Domain\TaskVms\TaskVmSettings;
use App\Domain\TaskVms\TaskVmSize;
use App\Infrastructure\TaskVms\RecipeImageBuilds;
use App\Infrastructure\TaskVms\UpCloudServers;
use App\Jobs\TaskVms\ProvisionTaskVm;
use App\Models\Task;
use App\Models\TaskVm;
use App\Models\TaskVmImageBuild;
use Illuminate\Contracts\Database\ConcurrencyErrorDetector;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Gives a group a new task VM of its declared size. Placement is serialized by the database transaction. */
final readonly class AllocateTaskVmAction
{
    public function __construct(
        private TaskVmSettings $settings,
        private TaskVmCapacity $capacity,
        private TaskVmPlacementPolicy $policy,
        private RecordTaskVmAction $record,
        private RecipeImageBuilds $images,
    ) {}

    /** @throws TaskCapacityException when no host has a free slot */
    public function execute(Task $group): TaskVm
    {
        $existing = TaskVm::forGroup($group);
        if ($existing instanceof TaskVm) {

            return $existing;
        }
        $size = TaskVmSize::forGroup($group, $this->settings->mediumProjectIds);
        try {
            $vm = $this->place($group, $size);
        } catch (Throwable $exception) {
            if (! app(ConcurrencyErrorDetector::class)->causedByConcurrencyError($exception)) {
                throw $exception;
            }
            throw new TaskCapacityException(TaskWait::Capacity, 'Task VM: another placement is running.');
        }

        if ($vm->wasRecentlyCreated) {
            ProvisionTaskVm::dispatch($vm->id);
        }

        return $vm;
    }

    private function place(Task $group, TaskVmSize $size): TaskVm
    {
        return DB::transaction(function () use ($group, $size): TaskVm {
            // Serialize admission with desired-recipe changes and image promotion.
            DB::table('task_vm_image_recipe')->where('id', 1)->update(['generation' => DB::raw('generation')]);
            $existing = TaskVm::forGroup($group);
            if ($existing instanceof TaskVm) {
                return $existing;
            }
            $recipe = $this->images->currentRecipe();
            $hosts = $this->capacity->activeHosts();
            $free = $this->capacity->freeSlots($hosts);
            $images = [];
            $waiting = [];
            foreach ($hosts as [$host, $node]) {
                $build = TaskVmImageBuild::query()->where('host_node_id', $host->nodeId)
                    ->where('recipe_identity', $recipe->identity)->where('state', TaskVmImageBuildState::Succeeded)
                    ->whereNotNull('promoted_at')->first();
                $base = $build?->images['base'] ?? null;
                if ($base !== null && $base['project'] === $host->project && $base['type'] === 'virtual-machine') {
                    $images[$host->nodeId] = $base['fingerprint'];
                } else {
                    unset($free[$host->nodeId]);
                    $waiting[] = $node->name;
                }
            }
            try {
                $target = $this->policy->choose($size, $group, $free);
                if ($waiting !== [] && $target->provider === TaskVmProviderKind::UpCloud) {
                    throw new TaskCapacityException(TaskWait::Capacity, TaskVmPlacementPolicy::NoCapacity);
                }
            } catch (TaskCapacityException $exception) {
                if ($waiting !== []) {
                    throw new TaskCapacityException(TaskWait::Capacity, 'Task VM: waiting for recipe '.substr($recipe->identity, 7, 12).' images on '.implode(', ', $waiting).'.');
                }
                throw $exception;
            }
            $fingerprint = $target->hostNodeId !== null ? $images[$target->hostNodeId] : app(UpCloudServers::class)->currentTemplate($recipe->identity);
            if ($fingerprint === null) {
                throw new TaskCapacityException(TaskWait::Capacity, 'Task VM: waiting for recipe '.substr($recipe->identity, 7, 12).' template on UpCloud.');
            }
            $vm = $this->record->execute($group, $target, $size);
            $vm->update(['recipe_identity' => $recipe->identity, 'image_fingerprint' => $target->hostNodeId !== null ? $fingerprint : null]);

            return $vm;
        });
    }
}
