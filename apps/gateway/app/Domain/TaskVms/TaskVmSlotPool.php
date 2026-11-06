<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Actions\Operations\StartOperationAction;
use App\Actions\TaskVms\RecordTaskVmAction;
use App\Actions\TaskVms\StartTaskVmDestroyAction;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\Swamp\PendingSshHostKey;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Infrastructure\TaskVms\RecipeImageBuilds;
use App\Jobs\TaskVms\RefillTaskVmSlots;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskVm;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * The ready slots of one task VM host (docs/cli/tasks/vm-slots.mmd): stopped task VMs, enrolled ahead of time for one
 * Project and size. {@see self::refill()} is the one rule that keeps the pool: it enrols a slot while the pool is below
 * its count, else destroys one stale slot. The Gateway runs it after every enrol, claim, destroy, image promotion and
 * settings change; each run starts at most one Operation, and the end of that Operation runs it again.
 */
final readonly class TaskVmSlotPool
{
    public const string EnrolKind = 'task_vm.slot_enrol';

    /** A host whose last enrol failed enrols again only this long after the failure. */
    public const int RetryMinutes = 15;

    /** A slot enrolled longer ago than this is stale: the ready copies of its seed expire after 20 hours. */
    public const int MaxAgeHours = 20;

    public function __construct(
        private TaskVmSettings $settings,
        private TaskVmCapacity $capacity,
        private RecordTaskVmAction $record,
        private TaskVmPromotedImage $images,
        private TaskVmImageInputs $inputs,
        private TaskVmProvisionIntent $provision,
        private TaskVmConvergeIntent $converge,
        private TaskVmEnrollmentIntent $enrollment,
        private StartOperationAction $start,
        private PendingSshHostKey $pendingKeys,
        private StartTaskVmDestroyAction $destroy,
        private RecipeImageBuilds $recipes,
        private SwampSshExecutionInput $ssh,
        private TaskVmFleetSeed $seeds,
        private AgentDelivery $agents,
    ) {}

    /** Runs the refill rule of the host; returns the Operation it started, or null when the pool needs nothing now. */
    public function refill(int $hostNodeId): ?Operation
    {
        // Most hosts keep no pool: they take no lock.
        if (! $this->settings->enabled || ! $this->settings->hasHost($hostNodeId)
            || ($this->settings->host($hostNodeId)->slots === null && ! TaskVm::query()->unclaimedSlots()->where('host_node_id', $hostNodeId)->exists())) {
            return null;
        }

        return $this->enrollment->admit(fn (): ?Operation => DB::transaction(function () use ($hostNodeId): ?Operation {
            // Serialize with placement and image promotion.
            DB::table('task_vm_image_recipe')->where('id', 1)->update(['generation' => DB::raw('generation')]);
            [$step, $vm] = $this->next($hostNodeId) ?? [null, null];

            return match ($step) {
                'enrol' => $this->enrol($hostNodeId),
                'destroy' => $vm instanceof TaskVm ? $this->destroy($vm) : null,
                default => null,
            };
        }));
    }

    /**
     * The one step the pool of the host needs now: `['enrol', null]`, `['destroy', $slot]`, or null. Nothing while a slot
     * of the host enrols or is destroyed; a failed slot is destroyed first; then an enrol while the pool is below its
     * count; then one stale or extra slot is destroyed.
     *
     * @return array{0:'enrol'|'destroy',1:TaskVm|null}|null
     */
    public function next(int $hostNodeId): ?array
    {
        $host = Node::query()->find($hostNodeId);
        if (! $this->settings->enabled || ! $this->settings->hasHost($hostNodeId) || ! $host instanceof Node) {
            return null;
        }
        $location = $this->settings->host($hostNodeId);
        $setting = $location->slots;
        $slots = TaskVm::query()->unclaimedSlots()->where('host_node_id', $hostNodeId)->lockForUpdate()->orderBy('id')->get();
        $failed = $slots->first(static fn (TaskVm $vm): bool => $vm->state === TaskVmState::Failed);
        if ($failed instanceof TaskVm) {
            return ['destroy', $failed];
        }
        if ($slots->contains(static fn (TaskVm $vm): bool => $vm->state !== TaskVmState::Parked)) {
            return null;
        }
        $current = $this->currentImage($host, $location);
        // A large or medium VM needs the nested fleet: like a clone, the pool waits while the image's seed holds no ready copies
        // (a refresh with `seed=keep`), so it neither enrols slots that no claim may take nor destroys the slots it has.
        if ($setting !== null && $current !== null && $setting->size->resources()->testNodes > 0 && ! $this->seeds->usable($hostNodeId, $current['build_id'])) {
            return null;
        }
        $count = $setting === null ? 0 : self::count($location, $setting);
        if ($setting !== null && $slots->count() < $count) {
            $free = $this->capacity->freeSlots([[$location, $host]])[$host->id] ?? 0;

            // An enrol runs a VM: it takes only capacity that nothing else uses.
            return $current !== null && Project::query()->whereKey($setting->projectId)->exists()
                && $free >= $setting->size->slots() && ! $this->recentlyFailed($host->id) ? ['enrol', null] : null;
        }
        $stale = $slots->first(fn (TaskVm $vm): bool => $this->stale($vm, $setting, $current))
            ?? ($slots->count() > $count ? $slots->first() : null);

        return $stale instanceof TaskVm ? ['destroy', $stale] : null;
    }

    /**
     * Takes the oldest ready slot that can serve the group: its Project and size are the group's, it is not stale, a
     * group with `current_recipe` gets only a slot of the current recipe, its host has capacity for it, and for a large or
     * medium slot its image's seed holds ready copies, as a clone needs. The slot
     * joins the group at once. Null when no slot fits: the caller clones, as without a pool. Call it in the admission
     * transaction of the group.
     */
    public function claim(Task $group): ?TaskVm
    {
        $ready = TaskVm::query()->unclaimedSlots()->where('state', TaskVmState::Parked)->where('slot_project_id', $group->project_id);
        // Most provisions find no ready slot of their Project: they take no lock.
        if (! $this->settings->enabled || ! $ready->exists() || TaskVm::forGroup($group) instanceof TaskVm) {
            return null;
        }
        $size = TaskVmSize::forGroup($group, $this->settings->mediumProjectIds, $this->settings->largeProjectIds);
        // Serialize with placement, the refill rule and image promotion.
        DB::table('task_vm_image_recipe')->where('id', 1)->update(['generation' => DB::raw('generation')]);
        $recipe = $group->current_recipe === true ? $this->recipes->currentRecipe()->identity : null;
        $candidates = TaskVm::query()->unclaimedSlots()->where('state', TaskVmState::Parked)->where('slot_project_id', $group->project_id)
            ->where('size', $size)->where('provider', TaskVmProviderKind::Incus)->lockForUpdate()->orderBy('id')->get();
        foreach ($candidates as $vm) {
            $hostNodeId = (int) $vm->host_node_id;
            $host = Node::query()->find($hostNodeId);
            if (! $this->settings->hasHost($hostNodeId) || ! $host instanceof Node || $host->status !== LifecycleStatus::Active) {
                continue;
            }
            $location = $this->settings->host($hostNodeId);
            if ($this->stale($vm, $location->slots, $this->currentImage($host, $location)) || ($recipe !== null && $vm->recipe_identity !== $recipe)
                || ($this->capacity->freeSlots([[$location, $host]])[$hostNodeId] ?? 0) < $size->slots() || $this->enrolOf($vm) === null
                || ($size->resources()->testNodes > 0 && ! $this->seeds->usable($hostNodeId, (int) $vm->image_build_id))) {
                continue;
            }
            $vm->update(['group_id' => $group->id, 'claimed_at' => now()]);
            RefillTaskVmSlots::dispatchFor($hostNodeId);

            return $vm;
        }

        return null;
    }

    /**
     * The VM part of the provision intent of a claimed slot: the intent its enrol ran with, and the receipt of that enrol
     * in place of the VM step's (`slot.receipt`). The run starts the slot and skips the VM step.
     *
     * @return array<string,mixed>
     */
    public function claimInput(TaskVm $vm): array
    {
        $enrol = $this->enrolOf($vm) ?? throw new ResourceOperationException('task_vm.provision_refused', 'A claimed slot needs the receipt of its enrol.', 409);
        $receipt = Arr::except(ValidatedData::object($enrol->result ?? []), ['image']);
        $input = Arr::except(ValidatedData::object($enrol->input), ['converge']);
        $host = $vm->hostNode ?? throw new ResourceOperationException('task_vm.provision_refused', 'A claimed slot needs its host.', 409);

        // The host target as it is now; the enrol captured it up to {@see self::MaxAgeHours} ago.
        return [...$input, 'host_ssh_execution' => $this->ssh->forNode($host),
            'slot' => ['receipt' => [...$receipt, 'result_json' => json_encode($receipt, JSON_THROW_ON_ERROR)]]];
    }

    /** Whether the slot's enrol installed another agent than the current pin: a release changed the pin since. */
    private function otherAgent(TaskVm $vm): bool
    {
        $installed = $this->enrolOf($vm)?->input['agent_sha256'] ?? null;

        return is_string($installed) && $vm->node instanceof Node && $installed !== $this->agents->pin($vm->node);
    }

    /** The succeeded enrol of a slot, whose intent and receipt its claim reuses. */
    private function enrolOf(TaskVm $vm): ?Operation
    {
        return Operation::query()->where('kind', self::EnrolKind)->where('target_type', 'task_vm')->where('target_id', $vm->id)
            ->where('status', OperationStatus::Succeeded->value)->latest('id')->first();
    }

    /**
     * Whether a ready slot can no longer serve a claim: no setting, another Project or size, an image that is not the
     * host's last promoted image, older than {@see self::MaxAgeHours}, or an enrol that installed another agent than the
     * current pin. An unknown current image makes no slot stale,
     * so a missing promotion record never drains the pool.
     *
     * @param  array{build_id:int,fingerprint:string,recipe_identity:string}|null  $current
     */
    public function stale(TaskVm $vm, ?TaskVmSlotSetting $setting, ?array $current): bool
    {
        return $setting === null || $vm->slot_project_id !== $setting->projectId || $vm->size !== $setting->size
            || ($current !== null && $vm->image_build_id !== $current['build_id'])
            || $vm->ready_at === null || $vm->ready_at->lt(now()->subHours(self::MaxAgeHours)) || $this->otherAgent($vm);
    }

    /**
     * The slots the host keeps: its `count`, at most as many VMs of the slot size as the host can run at once. A stopped
     * slot takes no capacity, so more could never all be claimed together.
     */
    public static function count(TaskVmHost $location, TaskVmSlotSetting $setting): int
    {
        return min($setting->count, intdiv($location->maxVms, $setting->size->slots()));
    }

    /** @return array{build_id:int,fingerprint:string,recipe_identity:string}|null the host's last promoted image */
    public function currentImage(Node $host, TaskVmHost $location): ?array
    {
        try {
            $image = $this->images->forHost($host, $location);
        } catch (ResourceOperationException) {
            return null;
        }
        $build = $this->images->latest($host);

        return $build === null ? null : ['build_id' => $build->id, ...$image];
    }

    /** The digest of what a task image carries in the release this Gateway runs: playbooks, pins and lock files. */
    public function releaseDigest(): string
    {
        return $this->inputs->digest(dirname(base_path(), 2));
    }

    private function enrol(int $hostNodeId): ?Operation
    {
        $host = Node::query()->findOrFail($hostNodeId);
        $location = $this->settings->host($hostNodeId);
        $setting = $location->slots;
        $current = $this->currentImage($host, $location);
        $project = $setting === null ? null : Project::query()->find($setting->projectId);
        if ($setting === null || $current === null || ! $project instanceof Project) {
            return null;
        }
        $vm = $this->record->execute(null, TaskVmPlacementTarget::incusHost($host->id), $setting->size, $project->id);
        $vm->update(['image_fingerprint' => $current['fingerprint'], 'recipe_identity' => $current['recipe_identity'],
            'image_build_id' => $current['build_id'], 'image_fallback' => false, 'slot_digest' => $this->releaseDigest()]);
        $input = $this->provision->capture($vm);
        $guest = $vm->refresh()->node;
        assert($guest instanceof Node);
        // "Converge once" of the enrol: the task VM role phases, with the nested network.
        $input['converge'] = $this->pendingKeys->during($guest->id, fn (): array => $this->converge->provisioning($vm,
            ['subnet' => TaskVmReadyCopiesIntent::Subnet, 'interface' => TaskVmReadyCopiesIntent::Network]));

        return $this->start->execute(self::EnrolKind, 'task_vm', $vm->id, ValidatedData::integer($input['node_id']), $input, 'task-vm-slots', null);
    }

    private function destroy(TaskVm $vm): ?Operation
    {
        try {
            return $this->destroy->execute($vm->id);
        } catch (ResourceOperationException $refused) {
            report($refused);

            return null;
        }
    }

    private function recentlyFailed(int $hostNodeId): bool
    {
        return Operation::query()->where('kind', self::EnrolKind)->where('node_id', $hostNodeId)
            ->whereIn('status', [OperationStatus::Failed->value, OperationStatus::Interrupted->value])
            ->where('finished_at', '>=', now()->subMinutes(self::RetryMinutes))->exists();
    }
}
