<?php

declare(strict_types=1);

namespace App\Actions\TaskVms;

use App\Domain\Tasks\TaskCapacityException;
use App\Domain\Tasks\TaskWait;
use App\Domain\TaskVms\TaskVmCapacity;
use App\Domain\TaskVms\TaskVmEnrollmentIntent;
use App\Domain\TaskVms\TaskVmFleetSeed;
use App\Domain\TaskVms\TaskVmImageBuildState;
use App\Domain\TaskVms\TaskVmPlacementPolicy;
use App\Domain\TaskVms\TaskVmProviderKind;
use App\Domain\TaskVms\TaskVmSeedFloor;
use App\Domain\TaskVms\TaskVmSettings;
use App\Domain\TaskVms\TaskVmSize;
use App\Infrastructure\TaskVms\RecipeImageBuilds;
use App\Infrastructure\TaskVms\UpCloudServers;
use App\Models\Task;
use App\Models\TaskVm;
use App\Models\TaskVmImageBuild;
use Closure;
use Illuminate\Contracts\Database\ConcurrencyErrorDetector;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Gives a group a new task VM of its declared size. Placement is serialized by the database transaction. An Incus host
 * serves the promoted image of the current recipe; without one, it serves its last promoted image when that image is at
 * most one day old, unless the group declared `current_recipe`.
 */
final readonly class AllocateTaskVmAction
{
    public function __construct(
        private TaskVmSettings $settings,
        private TaskVmCapacity $capacity,
        private TaskVmPlacementPolicy $policy,
        private RecordTaskVmAction $record,
        private RecipeImageBuilds $images,
        private TaskVmEnrollmentIntent $enrollment,
        private TaskVmFleetSeed $seeds,
    ) {}

    /** @throws TaskCapacityException when no host has a free slot */
    public function execute(Task $group): TaskVm
    {
        return $this->enrollment->admit(fn () => $this->allocate($group, static fn (TaskVm $vm) => app(StartTaskVmProvisionAction::class)->execute($vm->id)));
    }

    /**
     * State-only placement for an owning Task admission transaction. Starts no Operation or job. An admission that needs a size sets it
     * in `$size`, as the refresh of a task image does for a large VM; otherwise the group's topology and Project decide it.
     */
    public function capture(Task $group, ?int $hostNodeId = null, ?TaskVmSize $size = null, int $reserveSlots = 0): TaskVm
    {
        return $this->allocate($group, null, $hostNodeId, $size, $reserveSlots);
    }

    /** @param null|Closure(TaskVm):mixed $admit */
    private function allocate(Task $group, ?Closure $admit = null, ?int $hostNodeId = null, ?TaskVmSize $size = null, int $reserveSlots = 0): TaskVm
    {
        $existing = TaskVm::forGroup($group);
        if ($existing instanceof TaskVm) {

            return $existing;
        }
        $size ??= TaskVmSize::forGroup($group, $this->settings->mediumProjectIds, $this->settings->largeProjectIds);
        try {
            $vm = $this->place($group, $size, $admit, $hostNodeId, $reserveSlots);
        } catch (Throwable $exception) {
            if (! app(ConcurrencyErrorDetector::class)->causedByConcurrencyError($exception)) {
                throw $exception;
            }
            throw new TaskCapacityException(TaskWait::Capacity, 'Task VM: another placement is running.');
        }

        return $vm;
    }

    /**
     * A refresh of one host's task image passes `$hostNodeId`: the VM goes to that host only, and starts from the image
     * the host promoted last, whatever its recipe or age, because the refresh brings that image up to date.
     *
     * @param  null|Closure(TaskVm):mixed  $admit
     */
    private function place(Task $group, TaskVmSize $size, ?Closure $admit, ?int $hostNodeId, int $reserveSlots = 0): TaskVm
    {
        $resolvedIdentity = $this->images->currentRecipe()->identity;
        $template = null;
        try {
            $target = $this->policy->choose($size, $group, $this->capacity->freeSlots($this->capacity->activeHosts(), $reserveSlots), $reserveSlots);
            if ($target->provider === TaskVmProviderKind::UpCloud) {
                $template = app(UpCloudServers::class)->currentTemplate($resolvedIdentity);
            }
        } catch (TaskCapacityException) {
            // Admission rechecks capacity under the row lock.
        }

        return DB::transaction(function () use ($group, $size, $resolvedIdentity, $template, $admit, $hostNodeId, $reserveSlots): TaskVm {
            // Serialize admission with desired-recipe changes and image promotion.
            DB::table('task_vm_image_recipe')->where('id', 1)->update(['generation' => DB::raw('generation')]);
            $existing = TaskVm::forGroup($group);
            if ($existing instanceof TaskVm) {
                return $existing;
            }
            $recipe = $this->images->currentRecipe();
            $hosts = array_values(array_filter($this->capacity->activeHosts(), static fn (array $entry): bool => $hostNodeId === null || $entry[0]->nodeId === $hostNodeId));
            $free = $this->capacity->freeSlots($hosts, $reserveSlots);
            $images = [];
            $waiting = [];
            $unseeded = [];
            // A VM with test nodes starts the nested fleet from the ready copies of its host's seed (docs/reference/pipeline/2-development-environment.mmd).
            // The refresh of a host is pinned to it and builds those copies, so it is not filtered.
            $needsFleet = $hostNodeId === null && $size->resources()->testNodes > 0;
            foreach ($hosts as [$host, $node]) {
                if (! array_key_exists($host->nodeId, $free)) {
                    continue;
                }
                $promoted = TaskVmImageBuild::query()->where('host_node_id', $host->nodeId)->where('state', TaskVmImageBuildState::Succeeded)
                    ->whereNotNull('promoted_at')->orderByDesc('promoted_at')->latest('id');
                $build = $hostNodeId !== null ? (clone $promoted)->first() : (clone $promoted)->where('recipe_identity', $recipe->identity)->first();
                $fallback = false;
                if (! $build instanceof TaskVmImageBuild && $group->current_recipe !== true) {
                    // While the current recipe builds or after its build failed, the host's last promoted image serves for one day.
                    $build = $promoted->first();
                    $build = $build?->promoted_at?->gte(now()->subDay()) === true ? $build : null;
                    $fallback = true;
                }
                $base = $build?->images['base'] ?? null;
                if ($build !== null && $base !== null && $base['project'] === $host->project && $base['type'] === 'virtual-machine') {
                    // The seed follows the image the VM boots from, so a host with room is skipped when that image's seed holds no copies.
                    if ($needsFleet && $free[$host->nodeId] >= $size->slots() && ! $this->seeds->usable($host->nodeId, $build->id)) {
                        unset($free[$host->nodeId]);
                        $unseeded[] = $node->name;

                        continue;
                    }
                    $images[$host->nodeId] = ['build' => $build, 'fingerprint' => $base['fingerprint'], 'fallback' => $fallback];
                } else {
                    unset($free[$host->nodeId]);
                    $waiting[] = $node->name;
                }
            }
            try {
                $target = $this->policy->choose($size, $group, $free, $reserveSlots);
                if ($waiting !== [] && $target->provider === TaskVmProviderKind::UpCloud) {
                    throw new TaskCapacityException(TaskWait::Capacity, TaskVmPlacementPolicy::NoCapacity);
                }
            } catch (TaskCapacityException $exception) {
                if ($waiting !== []) {
                    throw new TaskCapacityException(TaskWait::Capacity, 'Task VM: waiting for recipe '.substr($recipe->identity, 7, 12).' images on '.implode(', ', $waiting).'.');
                }
                if ($unseeded !== []) {
                    throw new TaskCapacityException(TaskWait::Capacity, TaskVmPlacementPolicy::noCapacityFor($size, $reserveSlots)
                        .' Hosts with room but no usable nested fleet seed are skipped: '.implode(', ', $unseeded).'. Refresh their task image.'
                        .(app(TaskVmSeedFloor::class)->active() ? ' A proof needs copies built from the merge base of its commit, or a later release.' : ''));
                }
                throw $exception;
            }
            $image = $target->hostNodeId !== null ? $images[$target->hostNodeId] : null;
            $fingerprint = $image !== null ? $image['fingerprint'] : ($resolvedIdentity === $recipe->identity ? $template : null);
            if ($fingerprint === null) {
                throw new TaskCapacityException(TaskWait::Capacity, 'Task VM: waiting for recipe '.substr($recipe->identity, 7, 12).' template on UpCloud.');
            }
            $vm = $this->record->execute($group, $target, $size);
            $vm->update(['recipe_identity' => $image !== null ? $image['build']->recipe_identity : $recipe->identity, 'image_fingerprint' => $fingerprint,
                'image_build_id' => $image !== null ? $image['build']->id : null, 'image_fallback' => $image !== null && $image['fallback']]);
            $admit?->__invoke($vm);

            return $vm;
        });
    }
}
