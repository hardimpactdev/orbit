<?php

declare(strict_types=1);

namespace App\Actions\TaskVms;

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\TaskCapacityException;
use App\Domain\Tasks\TaskWait;
use App\Domain\TaskVms\TaskVmCapacity;
use App\Domain\TaskVms\TaskVmPlacementPolicy;
use App\Domain\TaskVms\TaskVmSettings;
use App\Domain\TaskVms\TaskVmSize;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\TaskVms\TaskVmBaseImageCache;
use App\Jobs\TaskVms\PrepareTaskVmRuntime;
use App\Jobs\TaskVms\ProvisionTaskVm;
use App\Jobs\TaskVms\RefillTaskVmPool;
use App\Models\Task;
use App\Models\TaskVm;
use Illuminate\Contracts\Database\ConcurrencyErrorDetector;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Gives a group its task VM, of the size that the group's declared topology and its Project need ({@see TaskVmSize}).
 * For a normal VM a warm VM goes first: the claim sets its group, a new Pi token, and `claimed_at`, and runs only the
 * group's steps, on the task-vms queue. A warm VM qualifies only while it launched the image that its host's `orbit-task-base`
 * names now, read from the host at the claim ({@see TaskVmBaseImageCache}). Without one, and always for a medium or
 * large VM, the claim records a new row where {@see TaskVmPlacementPolicy} puts it ({@see RecordTaskVmAction}), and
 * queues its creation. The database transaction serializes placement.
 */
final readonly class AllocateTaskVmAction
{
    public function __construct(
        private TaskVmSettings $settings,
        private TaskVmCapacity $capacity,
        private TaskVmPlacementPolicy $policy,
        private RecordTaskVmAction $record,
        private TaskVmBaseImageCache $images,
    ) {}

    /** @throws TaskCapacityException when no warm VM qualifies and no host has a free slot */
    public function execute(Task $group): TaskVm
    {
        $existing = TaskVm::forGroup($group);
        if ($existing instanceof TaskVm) {
            $this->dispatchPreparation($existing);

            return $existing;
        }
        $size = TaskVmSize::forGroup($group, $this->settings->mediumProjectIds);
        // A medium or large VM is always new, so it needs no warm VM image.
        $images = $size->resources()->warmEligible ? $this->warmImages() : [];

        try {
            [$vm, $warm] = $this->place($group, $images, $size);
        } catch (Throwable $exception) {
            if (! app(ConcurrencyErrorDetector::class)->causedByConcurrencyError($exception)) {
                throw $exception;
            }
            throw new TaskCapacityException(TaskWait::Capacity, 'Task VM: another placement is running.');
        }

        $this->dispatchPreparation($vm);
        if ($warm) {
            RefillTaskVmPool::dispatch();

            return $vm->refresh();
        }
        if ($vm->wasRecentlyCreated) {
            ProvisionTaskVm::dispatch($vm->id);
        }

        return $vm;
    }

    /** Recover a committed warm claim whose enqueue failed, without duplicating a queued or running job. */
    private function dispatchPreparation(TaskVm $vm): void
    {
        if ($vm->state === TaskVmState::Provisioning && $vm->claimed_at !== null && $vm->warmed_at !== null) {
            PrepareTaskVmRuntime::enqueue($vm->id);
        }
    }

    /**
     * The fingerprint that `orbit-task-base` names on each active host with a warm VM. A host whose image list
     * cannot be read is left out, so its warm VMs wait and the claim falls back to a new VM.
     *
     * @return array<int, string> host Node id => fingerprint
     */
    private function warmImages(): array
    {
        $withWarm = TaskVm::query()->pool()->where('state', TaskVmState::Warm)->whereNotNull('image_fingerprint')
            ->distinct()->pluck('host_node_id')->all();
        if ($withWarm === []) {
            return [];
        }
        $images = [];
        foreach ($this->capacity->activeHosts() as [$host, $node]) {
            $image = in_array($host->nodeId, $withWarm, true) ? $this->images->current($host, $node) : null;
            if ($image !== null) {
                $images[$host->nodeId] = $image;
            }
        }

        return $images;
    }

    /**
     * @param  array<int, string>  $images
     * @return array{TaskVm, bool} the row, and whether it is a warm VM this claim took
     */
    private function place(Task $group, array $images, TaskVmSize $size): array
    {
        return DB::transaction(function () use ($group, $images, $size): array {
            $existing = TaskVm::forGroup($group);
            if ($existing instanceof TaskVm) {
                return [$existing, false];
            }

            $free = $this->capacity->freeSlots($this->capacity->activeHosts());
            $warm = $size->resources()->warmEligible ? $this->takeWarm($group, $images, $free) : null;
            if ($warm instanceof TaskVm) {
                return [$warm, true];
            }

            return [$this->record->execute($group, $this->policy->choose($size, $group, $free), $size), false];
        });
    }

    /**
     * Takes the oldest current warm VM on the first host in {@see TaskVmPlacementPolicy::warmHostOrder()} that has one.
     *
     * @param  array<int, string>  $images
     * @param  array<int, int>  $free
     */
    private function takeWarm(Task $group, array $images, array $free): ?TaskVm
    {
        foreach ($this->policy->warmHostOrder($free) as $hostId) {
            if (! isset($images[$hostId])) {
                continue;
            }
            $candidates = TaskVm::query()->pool()->where('state', TaskVmState::Warm)->where('host_node_id', $hostId)
                ->where('image_fingerprint', $images[$hostId])->whereNotNull('node_id')
                ->whereHas('node', static fn ($node) => $node->where('status', LifecycleStatus::Active))
                ->orderBy('id')->get();
            foreach ($candidates as $vm) {
                // Only a row that is still warm and unassigned moves, so a destroy or another claim that came first wins.
                $taken = TaskVm::query()->whereKey($vm->id)->pool()->where('state', TaskVmState::Warm)->update([
                    'group_id' => $group->id,
                    'state' => TaskVmState::Provisioning,
                    'claimed_at' => now(),
                ]);
                if ($taken === 1) {
                    $vm->refresh()->update(['pi_token' => RecordTaskVmAction::piToken()]);

                    return $vm;
                }
            }
        }

        return null;
    }
}
