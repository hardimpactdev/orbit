<?php

declare(strict_types=1);

namespace App\Actions\TaskVms;

use App\Domain\Tasks\TaskCapacityException;
use App\Domain\Tasks\TaskWait;
use App\Domain\TaskVms\TaskVmCapacity;
use App\Domain\TaskVms\TaskVmEnrollmentIntent;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmImageBuildState;
use App\Domain\TaskVms\TaskVmPlacementPolicy;
use App\Domain\TaskVms\TaskVmProviderKind;
use App\Domain\TaskVms\TaskVmSettings;
use App\Domain\TaskVms\TaskVmSize;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\TaskVms\RecipeImageBuilds;
use App\Infrastructure\TaskVms\UpCloudServers;
use App\Models\Node;
use App\Models\NodeFact;
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
 *
 * A host listed in `task_vms.slot_claim_hosts` gives a free slot instead: the group takes the lowest free slot of the
 * host with the most free slots, in the same transaction, and no VM is created or enrolled for it. Such a host never
 * creates VMs of its own. No host is listed by default, so every task enrols a new VM as before.
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
    ) {}

    /** @throws TaskCapacityException when no host has a free slot */
    public function execute(Task $group): TaskVm
    {
        return $this->enrollment->admit(fn () => $this->allocate($group, static fn (TaskVm $vm) => app(StartTaskVmProvisionAction::class)->execute($vm->id)));
    }

    /** State-only placement for an owning Task admission transaction. Starts no Operation or job. */
    public function capture(Task $group): TaskVm
    {
        return $this->allocate($group);
    }

    /** @param null|Closure(TaskVm):mixed $admit */
    private function allocate(Task $group, ?Closure $admit = null): TaskVm
    {
        $existing = TaskVm::forGroup($group);
        if ($existing instanceof TaskVm) {

            return $existing;
        }
        $size = TaskVmSize::forGroup($group, $this->settings->mediumProjectIds, $this->settings->largeProjectIds);
        try {
            $vm = $this->place($group, $size, $admit);
        } catch (Throwable $exception) {
            if (! app(ConcurrencyErrorDetector::class)->causedByConcurrencyError($exception)) {
                throw $exception;
            }
            throw new TaskCapacityException(TaskWait::Capacity, 'Task VM: another placement is running.');
        }

        return $vm;
    }

    /** @param null|Closure(TaskVm):mixed $admit */
    private function place(Task $group, TaskVmSize $size, ?Closure $admit): TaskVm
    {
        $resolvedIdentity = $this->images->currentRecipe()->identity;
        $template = null;
        try {
            $target = $this->policy->choose($size, $group, $this->capacity->freeSlots($this->ownHosts($this->capacity->activeHosts())));
            if ($target->provider === TaskVmProviderKind::UpCloud) {
                $template = app(UpCloudServers::class)->currentTemplate($resolvedIdentity);
            }
        } catch (TaskCapacityException) {
            // Admission rechecks capacity under the row lock.
        }

        return DB::transaction(function () use ($group, $size, $resolvedIdentity, $template, $admit): TaskVm {
            // Serialize admission with desired-recipe changes and image promotion.
            DB::table('task_vm_image_recipe')->where('id', 1)->update(['generation' => DB::raw('generation')]);
            $existing = TaskVm::forGroup($group);
            if ($existing instanceof TaskVm) {
                return $existing;
            }
            $recipe = $this->images->currentRecipe();
            // A slot has one size, and its VM holds no topology: a bigger group goes to a host that creates its own VMs.
            $slot = $size === TaskVmSize::Normal ? $this->claimSlot($group, $recipe->identity) : null;
            if ($slot instanceof TaskVm) {
                $admit?->__invoke($slot);

                return $slot;
            }
            $hosts = $this->ownHosts($this->capacity->activeHosts());
            $free = $this->capacity->freeSlots($hosts);
            $images = [];
            $waiting = [];
            foreach ($hosts as [$host, $node]) {
                if (! array_key_exists($host->nodeId, $free)) {
                    continue;
                }
                $image = $this->image($host, $group, $recipe->identity);
                if ($image !== null) {
                    $images[$host->nodeId] = $image;
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

    /**
     * The hosts that create VMs of their own: every host that does not claim slots.
     *
     * @param  list<array{TaskVmHost, Node}>  $hosts
     * @return list<array{TaskVmHost, Node}>
     */
    private function ownHosts(array $hosts): array
    {
        return array_values(array_filter($hosts, fn (array $pair): bool => ! $this->claimsSlots($pair[0])));
    }

    /** The host claims slots: it is listed and has enrolled at least one. A listed host with no slot is served as an unlisted one. */
    private function claimsSlots(TaskVmHost $host): bool
    {
        return $this->settings->claimsSlots($host->nodeId) && TaskVm::query()->live()->where('host_node_id', $host->nodeId)->whereNotNull('slot')->exists();
    }

    /**
     * The promoted image a host serves the group: the one of the current recipe or, while that builds or after its build
     * failed, the host's last promoted image when it is at most one day old, unless the group declared `current_recipe`.
     *
     * @return null|array{build: TaskVmImageBuild, fingerprint: string, fallback: bool}
     */
    private function image(TaskVmHost $host, Task $group, string $recipeIdentity, bool $allowFallback = true): ?array
    {
        $promoted = TaskVmImageBuild::query()->where('host_node_id', $host->nodeId)->where('state', TaskVmImageBuildState::Succeeded)
            ->whereNotNull('promoted_at')->orderByDesc('promoted_at')->latest('id');
        $build = (clone $promoted)->where('recipe_identity', $recipeIdentity)->first();
        $fallback = false;
        if (! $build instanceof TaskVmImageBuild && $allowFallback && $group->current_recipe !== true) {
            $build = $promoted->first();
            $build = $build?->promoted_at?->gte(now()->subDay()) === true ? $build : null;
            $fallback = true;
        }
        $base = $build?->images['base'] ?? null;

        return $build !== null && $base !== null && $base['project'] === $host->project && $base['type'] === 'virtual-machine'
            ? ['build' => $build, 'fingerprint' => $base['fingerprint'], 'fallback' => $fallback] : null;
    }

    /**
     * Takes the lowest free slot of the slot-claiming host with the most free slots that has a usable image, or null
     * when none has. Call it inside the placement transaction: the conditional update is the claim. No Node, address,
     * token or key is made. The slot Node's facts are removed, so the first `host` fact after this claim is the new
     * VM's own report (an unchanged report is never rewritten, so an old fact would otherwise stay).
     */
    private function claimSlot(Task $group, string $recipeIdentity): ?TaskVm
    {
        $best = null;
        foreach ($this->capacity->activeHosts() as [$host]) {
            if (! $this->claimsSlots($host)) {
                continue;
            }
            // A slot without its Node cannot be claimed. Only the current recipe's image has the slot units, so no fallback image.
            $slots = TaskVm::query()->where('host_node_id', $host->nodeId)->whereNotNull('slot')->whereNotNull('node_id')->where('state', TaskVmState::Free)->orderBy('slot')->get();
            $image = $slots->isEmpty() ? null : $this->image($host, $group, $recipeIdentity, allowFallback: false);
            if ($image !== null && ($best === null || $slots->count() > $best[0]->count())) {
                $best = [$slots, $image];
            }
        }
        if ($best === null) {
            return null;
        }
        [$slots, $image] = $best;
        $vm = $slots->first();
        if (! $vm instanceof TaskVm) {
            return null;
        }
        $claimed = TaskVm::query()->whereKey($vm->id)->where('state', TaskVmState::Free)->update([
            'group_id' => $group->id, 'state' => TaskVmState::Provisioning, 'claimed_at' => now(), 'ready_at' => null, 'error_code' => null, 'error_message' => null,
            'recipe_identity' => $image['build']->recipe_identity, 'image_fingerprint' => $image['fingerprint'], 'image_build_id' => $image['build']->id,
            'image_fallback' => $image['fallback'],
        ]);
        if ($claimed !== 1) {
            return null;
        }
        NodeFact::query()->where('node_id', $vm->node_id)->delete();

        return $vm->refresh();
    }
}
