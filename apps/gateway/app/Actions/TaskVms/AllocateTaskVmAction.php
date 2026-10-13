<?php

declare(strict_types=1);

namespace App\Actions\TaskVms;

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\TaskCapacityException;
use App\Domain\Tasks\TaskWait;
use App\Domain\TaskVms\TaskVmCapacity;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmPlacementPolicy;
use App\Domain\TaskVms\TaskVmSettings;
use App\Domain\TaskVms\TaskVmSize;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\Processes\CommandDeadline;
use App\Infrastructure\TaskVms\TaskVmBaseImageCache;
use App\Infrastructure\TaskVms\TaskVmRuntime;
use App\Jobs\TaskVms\EnsureWarmClaimReady;
use App\Jobs\TaskVms\PrepareTaskVmRuntime;
use App\Jobs\TaskVms\ProvisionTaskVm;
use App\Jobs\TaskVms\RefillTaskVmPool;
use App\Models\Task;
use App\Models\TaskVm;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Gives a group its task VM, of the size that the group's declared topology and its Project need ({@see TaskVmSize}).
 * For a normal VM a warm VM goes first: the claim sets its group, a new Pi token, and `claimed_at`, and runs only the
 * group's steps, in the claim. A warm VM qualifies only while it launched the image that its host's `orbit-task-base`
 * names now, read from the host at the claim ({@see TaskVmBaseImageCache}). Without one, and always for a medium or
 * large VM, the claim records a new row where {@see TaskVmPlacementPolicy} puts it ({@see RecordTaskVmAction}), and
 * queues its creation. Placement runs under the lock of {@see TaskVmCapacity}.
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
            return $existing;
        }
        $size = TaskVmSize::forGroup($group, $this->settings->mediumProjectIds);
        // A medium or large VM is always new, so it needs no warm VM image.
        $images = $size->resources()->warmEligible ? $this->warmImages() : [];

        try {
            [$vm, $warm] = $this->capacity->withPlacementLock(fn (): array => $this->place($group, $images, $size));
        } catch (LockTimeoutException) {
            throw new TaskCapacityException(TaskWait::Capacity, 'Task VM: another placement is running.');
        }

        if ($warm) {
            RefillTaskVmPool::dispatch();
            $this->prepareNow($vm);

            return $vm->refresh();
        }
        if ($vm->wasRecentlyCreated) {
            ProvisionTaskVm::dispatch($vm->id);
        }

        return $vm;
    }

    /**
     * Runs the group steps of a warm claim in the claim itself: the model key, the Pi files, and `pi-server`, about
     * 1.5 seconds. Each step has a short limit, and the whole run ends after {@see TaskVmRuntime::InlineSeconds}, well
     * inside the 300-second tick lock: every SSH command and CLIProxyAPI request stops at that deadline. A VM without
     * the pinned Pi, and any step that runs out of time, is left to the queued job. A guard job queued first finishes
     * the steps when the tick dies during them. So they never wait for the task VM worker, which runs one job at a
     * time and may be in an enrollment or an image build. The placement lock is released by then, and the claim holds
     * no transaction. A failure queues `PrepareTaskVmRuntime`, which retries the steps and fails the row when it has
     * no retries left.
     */
    private function prepareNow(TaskVm $vm): void
    {
        // Queued first, so a tick that dies during the steps still leaves a job that finishes them.
        EnsureWarmClaimReady::dispatch($vm->id)->delay(now()->addSeconds(EnsureWarmClaimReady::DelaySeconds));
        try {
            app(CommandDeadline::class)->within(TaskVmRuntime::InlineSeconds, static fn () => app(PrepareTaskVmRuntimeAction::class)->execute($vm->id, inline: true));
        } catch (Throwable $exception) {
            Log::warning('The group steps of a warm task VM claim failed; the task VM worker retries them.', ['task_vm' => $vm->name, 'error_code' => TaskVmException::codeOf($exception), 'error' => $exception->getMessage()]);
            PrepareTaskVmRuntime::dispatch($vm->id);
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
