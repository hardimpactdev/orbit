<?php

declare(strict_types=1);

namespace App\Actions\TaskVms;

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\TaskCapacityException;
use App\Domain\Tasks\TaskWait;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmPlacementTarget;
use App\Domain\TaskVms\TaskVmProviderKind;
use App\Domain\TaskVms\TaskVmSettings;
use App\Domain\TaskVms\TaskVmSize;
use App\Domain\TaskVms\TaskVmState;
use App\Domain\WireGuard\WireGuardAddressAllocator;
use App\Infrastructure\Processes\CommandDeadline;
use App\Infrastructure\TaskVms\IncusHost;
use App\Infrastructure\TaskVms\TaskVmRuntime;
use App\Jobs\TaskVms\EnsureWarmClaimReady;
use App\Jobs\TaskVms\PrepareTaskVmRuntime;
use App\Jobs\TaskVms\ProvisionTaskVm;
use App\Jobs\TaskVms\RefillTaskVmPool;
use App\Models\Node;
use App\Models\Task;
use App\Models\TaskVm;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Gives a group its task VM, of the size that the group's declared topology and its Project need ({@see TaskVmSize}).
 * For a normal VM a warm VM goes
 * first: the claim sets its group, a new Pi token, and `claimed_at`, and runs only the
 * group's steps, in the claim. A warm VM qualifies only while it launched the image that its host's
 * `orbit-task-base` names now, read from the host at the claim. Without one, and always for a medium or large VM, the claim
 * records a new row on the active host with the most free slots that has room for the size, and queues its creation.
 * When no host has room for a normal VM, an opted-in Project's group may overflow to an UpCloud VM
 * ({@see self::host()}); a medium or large VM never does. A new row is named `tvm-<row id>`, so a retry after a destroyed VM
 * never reuses a name, and it holds its reserved WireGuard address from the start.
 *
 * One placement runs at a time under a cache lock, so two claims never take the same warm VM or both take a
 * host's last slot. The pool refill takes the same lock.
 */
final readonly class AllocateTaskVmAction
{
    public const string NoCapacity = 'Task VM: no capacity.';

    /** Every slot is taken, and at least one of them is a warm VM that is still starting. */
    public const string WarmStarting = 'Task VM: waiting for a warm VM.';

    private const string PlacementLock = 'task-vms:placement';

    /** The image read at a claim runs in the scheduler tick, so it gets a short limit. */
    private const float ImageReadSeconds = 15.0;

    private const int ImageCacheSeconds = 10;

    private const int ImageFailureSeconds = 60;

    public function __construct(
        private TaskVmSettings $settings,
        private WireGuardAddressAllocator $addresses,
        private IncusHost $incus,
    ) {}

    /** @throws TaskCapacityException when no warm VM qualifies and no host has a free slot */
    public function execute(Task $group): TaskVm
    {
        $existing = TaskVm::query()->live()->where('group_id', $group->id)->first();
        if ($existing instanceof TaskVm) {
            return $existing;
        }
        $size = TaskVmSize::forGroup($group, $this->settings->mediumProjectIds);
        // A medium or large VM is always new, so it needs no warm VM image.
        $images = $size->resources()->warmEligible ? $this->warmImages() : [];

        try {
            [$vm, $warm] = self::locked(fn (): array => $this->place($group, $images, $size));
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
        $job = new PrepareTaskVmRuntime($vm->id);
        $job->inline = true;
        try {
            app(CommandDeadline::class)->within(TaskVmRuntime::InlineSeconds, fn () => app()->call($job->handle(...)));
        } catch (Throwable $exception) {
            Log::warning('The group steps of a warm task VM claim failed; the task VM worker retries them.', ['task_vm' => $vm->name, 'error_code' => TaskVmException::codeOf($exception), 'error' => $exception->getMessage()]);
            PrepareTaskVmRuntime::dispatch($vm->id);
        }
    }

    /**
     * Runs `$placement` under the placement lock, waiting up to 15 seconds for it.
     *
     * @template T
     *
     * @param  Closure(): T  $placement
     * @return T
     *
     * @throws LockTimeoutException
     */
    public static function locked(Closure $placement): mixed
    {
        return Cache::lock(self::PlacementLock, 30)->block(15, $placement);
    }

    /**
     * Records a new `provisioning` row on the host: a group's VM, or a pool VM when `$group` is null. A pool row
     * gets no Pi token; a claim gives it one. Call it under the placement lock, inside a transaction.
     */
    public function record(?Task $group, TaskVmPlacementTarget $target, TaskVmSize $size = TaskVmSize::Normal): TaskVm
    {
        $vm = TaskVm::query()->create([
            'group_id' => $group?->id,
            'host_node_id' => $target->hostNodeId,
            'provider' => $target->provider,
            'size' => $size,
            'name' => 'tvm-pending-'.Str::uuid(),
            'state' => TaskVmState::Provisioning,
            'wireguard_ip' => $this->addresses->nextIn(
                $this->settings->wireguardRange,
                array_values(TaskVm::query()->live()->get()->map(static fn (TaskVm $vm): string => $vm->wireguard_ip)->all()),
            ),
            'pi_token' => $group instanceof Task ? self::piToken() : null,
            'claimed_at' => $group instanceof Task ? now() : null,
        ]);
        $vm->update(['name' => 'tvm-'.$vm->id]);

        return $vm;
    }

    /**
     * The free slots of each configured host that is an active Node, in config order: `max_vms` minus the slots of
     * every task VM on the host that is not destroyed or failed, warm and parked ones too. A failed VM holds no slot, so
     * it never blocks placement: its group asks for assistance, and the pool refill destroys a failed pool VM. A normal
     * VM takes one slot, a medium VM two, and a large VM three. A host over its budget has 0.
     *
     * @return array<int, int> host Node id => free slots
     */
    public function freeSlots(): array
    {
        $active = Node::query()->whereKey(array_map(static fn (TaskVmHost $host): int => $host->nodeId, $this->settings->hosts))
            ->where('status', LifecycleStatus::Active)->pluck('id')->all();
        $used = [];
        foreach (TaskVm::query()->countedAgainstCap(TaskVmProviderKind::Incus)->get(['host_node_id', 'size']) as $vm) {
            $host = (int) $vm->host_node_id;
            $used[$host] = ($used[$host] ?? 0) + $vm->size->slots();
        }
        $free = [];
        foreach ($this->settings->hosts as $host) {
            if (in_array($host->nodeId, $active, true)) {
                $free[$host->nodeId] = max(0, $host->maxVms - ($used[$host->nodeId] ?? 0));
            }
        }

        return $free;
    }

    /**
     * The fingerprint that `orbit-task-base` names on each active host with a warm VM. A host whose image list
     * cannot be read is left out, so its warm VMs wait and the claim falls back to a new VM.
     *
     * @return array<int, string> host Node id => fingerprint
     */
    private function warmImages(): array
    {
        $hosts = TaskVm::query()->pool()->where('state', TaskVmState::Warm)->whereNotNull('image_fingerprint')
            ->distinct()->pluck('host_node_id')->all();
        $images = [];
        foreach ($this->settings->hosts as $host) {
            $node = in_array($host->nodeId, $hosts, true) ? Node::query()->whereKey($host->nodeId)->where('status', LifecycleStatus::Active)->first() : null;
            if (! $node instanceof Node) {
                continue;
            }
            $image = $this->hostImage($host, $node);
            if ($image !== null) {
                $images[$host->nodeId] = $image;
            }
        }

        return $images;
    }

    /**
     * The host's `orbit-task-base`, read over SSH at most every 10 seconds. After a failed read, the host's warm
     * VMs wait for 60 seconds, so a host that hangs costs one 15-second read a minute, not one per claim. A promote
     * forgets the reading, so a claim right after a build or sync reads the new image.
     */
    private function hostImage(TaskVmHost $host, Node $node): ?string
    {
        $key = self::imageKey($node->id);
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return is_string($cached['image'] ?? null) ? $cached['image'] : null;
        }
        try {
            $image = $this->incus->aliasTarget($host, $node, TaskVmHost::BaseImage, self::ImageReadSeconds);
        } catch (Throwable $exception) {
            Log::warning('A claim could not read the base image of a task VM host; its warm VMs wait a minute.', ['node_id' => $node->id, 'error' => $exception->getMessage()]);
            Cache::put($key, ['image' => null], self::ImageFailureSeconds);

            return null;
        }
        Cache::put($key, ['image' => $image], self::ImageCacheSeconds);

        return $image;
    }

    /** Forgets a host's cached image, after its `orbit-task-base` moved. */
    public static function forgetImage(int $hostNodeId): void
    {
        Cache::forget(self::imageKey($hostNodeId));
    }

    private static function imageKey(int $hostNodeId): string
    {
        return 'task-vms:claim-image:'.$hostNodeId;
    }

    /**
     * @param  array<int, string>  $images
     * @return array{TaskVm, bool} the row, and whether it is a warm VM this claim took
     */
    private function place(Task $group, array $images, TaskVmSize $size): array
    {
        return DB::transaction(function () use ($group, $images, $size): array {
            $existing = TaskVm::query()->live()->where('group_id', $group->id)->first();
            if ($existing instanceof TaskVm) {
                return [$existing, false];
            }

            $free = $this->freeSlots();
            $warm = $size->resources()->warmEligible ? $this->takeWarm($group, $images, $free) : null;
            if ($warm instanceof TaskVm) {
                return [$warm, true];
            }

            return [$this->record($group, $this->host($free, $group, $size), $size), false];
        });
    }

    /**
     * Takes the oldest current warm VM on the host with the most free slots among the hosts that have one.
     *
     * @param  array<int, string>  $images
     * @param  array<int, int>  $free
     */
    private function takeWarm(Task $group, array $images, array $free): ?TaskVm
    {
        $order = array_keys($free);
        // usort is stable, so hosts with as many free slots keep their config order.
        usort($order, static fn (int $a, int $b): int => $free[$b] <=> $free[$a]);
        foreach ($order as $hostId) {
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
                    $vm->refresh()->update(['pi_token' => self::piToken()]);

                    return $vm;
                }
            }
        }

        return null;
    }

    /**
     * The active host with the most free slots, when it has room for the size. Hosts with as many free slots keep
     * their config order. Without room, a VM of a size that UpCloud supports goes to UpCloud: only while the overflow
     * is on, the group's Project is opted in, and fewer than `max_vms` UpCloud VMs count against its cap, failed and
     * parked ones too. This row cap is the placement rule; `UpCloudTaskVmProvider` also counts the account's servers
     * before each create, as its own cost guard. A VM of another size waits for an Incus host.
     *
     * @param  array<int, int>  $free
     */
    private function host(array $free, Task $group, TaskVmSize $size): TaskVmPlacementTarget
    {
        $best = null;
        $bestFree = $size->slots() - 1;
        foreach ($free as $hostId => $slots) {
            if ($slots > $bestFree) {
                [$best, $bestFree] = [$hostId, $slots];
            }
        }
        if ($best !== null) {
            return TaskVmPlacementTarget::incusHost($best);
        }
        if (! TaskVmProviderKind::UpCloud->supports($size)) {
            throw new TaskCapacityException(TaskWait::Capacity, self::noCapacityFor($size));
        }
        $upcloud = $this->settings->upcloud;
        if ($upcloud?->allows($group->project_id) === true
            && TaskVm::query()->countedAgainstCap(TaskVmProviderKind::UpCloud)->count() < $upcloud->maxVms) {
            return TaskVmPlacementTarget::upCloud();
        }
        $starting = TaskVm::query()->pool()->where('state', TaskVmState::Provisioning)->whereIn('host_node_id', array_keys($free))->exists();

        throw new TaskCapacityException(TaskWait::Capacity, $starting ? self::WarmStarting : self::NoCapacity);
    }

    /**
     * The wait reason of a VM that only an Incus host can run, while no host has its free slots: `Task VM: no host has 3
     * free slots for a large VM.` for a group with a declared topology, or 2 for a medium VM of a Project in
     * `task_vms.medium_projects`.
     */
    public static function noCapacityFor(TaskVmSize $size): string
    {
        return "Task VM: no host has {$size->slots()} free slots for a {$size->value} VM.";
    }

    private static function piToken(): string
    {
        return bin2hex(random_bytes(32));
    }
}
