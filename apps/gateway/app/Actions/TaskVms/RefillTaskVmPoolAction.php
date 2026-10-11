<?php

declare(strict_types=1);

namespace App\Actions\TaskVms;

use App\Domain\Shared\LifecycleStatus;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmSettings;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\TaskVms\IncusHost;
use App\Infrastructure\TaskVms\TaskVmBaseImage;
use App\Jobs\TaskVms\DestroyTaskVm;
use App\Jobs\TaskVms\ProvisionTaskVm;
use App\Models\Node;
use App\Models\TaskVm;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Keeps each task VM host's pool at its `warm_vms`. One run does three things, in this order:
 *
 * 1. It reads each host's base image and the image of each instance, records the image of each warm VM, and
 *    destroys a warm VM whose image is no longer `orbit-task-base` or whose instance is gone.
 * 2. Under the placement lock, it destroys failed pool VMs and warm VMs over the target, and records new pool
 *    rows up to the target within the host's free slots. The target is 0 while task VMs are disabled, so the
 *    pool drains. Pool VMs that are still starting are never cut off; a later run destroys any excess.
 * 3. It queues `DestroyTaskVm` for every pool row that is `destroying`, and `ProvisionTaskVm` for each new row.
 *
 * A host whose pool VM failed waits before it creates another: 1 minute after the first failure, doubling up to
 * 1 hour. Each failure logs an error, and each skipped refill a warning. A warm VM that becomes `warm` clears it.
 */
final readonly class RefillTaskVmPoolAction
{
    private const int BackoffSeconds = 60;

    private const int MaxBackoffSeconds = 3600;

    public function __construct(
        private TaskVmSettings $settings,
        private AllocateTaskVmAction $allocate,
        private IncusHost $incus,
        private TaskVmBaseImage $images,
    ) {}

    /** @return array{created: int, destroying: int} */
    public function execute(): array
    {
        $hosts = $this->activeHosts();
        foreach ($hosts as [$host, $node]) {
            $this->replaceStale($host, $node);
        }

        try {
            $created = AllocateTaskVmAction::locked(fn (): array => DB::transaction(fn (): array => $this->balance($hosts)));
        } catch (LockTimeoutException) {
            Log::warning('The task VM pool refill waits for another placement; the next run tries again.');
            $created = [];
        }
        $this->reportUnmanaged(array_map(static fn (array $entry): int => $entry[0]->nodeId, $hosts));

        $destroying = TaskVm::query()->pool()->where('state', TaskVmState::Destroying)->get(['id']);
        foreach ($destroying as $vm) {
            DestroyTaskVm::dispatch($vm->id);
        }
        foreach ($created as $id) {
            ProvisionTaskVm::dispatch($id);
        }

        return ['created' => count($created), 'destroying' => $destroying->count()];
    }

    /** A pool VM failed: the host waits before its next pool VM, and the log says why and until when. */
    public static function failed(TaskVm $vm, string $code, string $message): void
    {
        $key = self::backoffKey($vm->host_node_id);
        $failures = self::backoff($vm->host_node_id)['failures'] + 1;
        $wait = min(self::MaxBackoffSeconds, self::BackoffSeconds * 2 ** min($failures - 1, 6));
        $until = now()->getTimestamp() + $wait;
        Cache::put($key, ['failures' => $failures, 'until' => $until], self::MaxBackoffSeconds * 2);
        Log::error('A warm task VM failed; its host creates no pool VM until the wait ends.', [
            'task_vm' => $vm->name, 'node_id' => $vm->host_node_id, 'error_code' => $code, 'error' => $message,
            'failures' => $failures, 'retry_at' => date(DATE_ATOM, $until),
        ]);
    }

    /** A pool VM became warm, so its host's failures are forgotten. */
    public static function warmed(TaskVm $vm): void
    {
        Cache::forget(self::backoffKey($vm->host_node_id));
    }

    /** @return list<array{TaskVmHost, Node}> the configured hosts whose Node is active, in config order */
    private function activeHosts(): array
    {
        $nodes = Node::query()->whereKey(array_map(static fn (TaskVmHost $host): int => $host->nodeId, $this->settings->hosts))
            ->where('status', LifecycleStatus::Active)->get()->keyBy('id');
        $hosts = [];
        foreach ($this->settings->hosts as $host) {
            $node = $nodes->get($host->nodeId);
            if ($node instanceof Node) {
                $hosts[] = [$host, $node];
            }
        }

        return $hosts;
    }

    /**
     * Records the image each warm VM launched, and moves a warm VM to `destroying` when that image is not the
     * host's `orbit-task-base` any more or its instance is gone. A host that cannot be read keeps its warm VMs:
     * a claim reads the image again itself.
     */
    private function replaceStale(TaskVmHost $host, Node $node): void
    {
        $warm = TaskVm::query()->pool()->where('state', TaskVmState::Warm)->where('host_node_id', $node->id)->get();
        if ($warm->isEmpty()) {
            return;
        }
        try {
            $current = $this->incus->aliasTarget($host, $node, TaskVmHost::BaseImage, 120.0);
            $launched = $this->images->instances($host, $node);
        } catch (Throwable $exception) {
            Log::error('The task VM pool could not read the images of a host.', ['node_id' => $node->id, 'error_code' => TaskVmException::codeOf($exception), 'error' => $exception->getMessage()]);

            return;
        }
        foreach ($warm as $vm) {
            $image = $launched[$vm->name] ?? null;
            $query = TaskVm::query()->whereKey($vm->id)->pool()->where('state', TaskVmState::Warm);
            if ($image !== null && $image === $current) {
                if ($vm->image_fingerprint !== $image) {
                    $query->update(['image_fingerprint' => $image]);
                }

                continue;
            }
            if ($query->update(['state' => TaskVmState::Destroying]) === 1) {
                Log::info('Replacing a warm task VM.', [
                    'task_vm' => $vm->name, 'node_id' => $node->id,
                    'reason' => $image === null ? 'its instance is gone' : 'its base image is not the current one',
                    'image' => $image, 'current' => $current,
                ]);
            }
        }
    }

    /**
     * @param  list<array{TaskVmHost, Node}>  $hosts
     * @return list<int> the new pool rows
     */
    private function balance(array $hosts): array
    {
        $free = $this->allocate->freeSlots();
        $created = [];
        foreach ($hosts as [$host, $node]) {
            $pool = TaskVm::query()->live()->pool()->where('host_node_id', $node->id)->orderBy('id')->get();
            foreach ($pool->where('state', TaskVmState::Failed) as $failed) {
                TaskVm::query()->whereKey($failed->id)->where('state', TaskVmState::Failed)->update(['state' => TaskVmState::Destroying]);
            }

            $target = $this->settings->enabled ? $host->warmVms : 0;
            $warm = $pool->where('state', TaskVmState::Warm);
            $starting = $pool->where('state', TaskVmState::Provisioning)->count();
            $excess = $warm->count() + $starting - $target;
            foreach ($warm->take(max(0, min($excess, $warm->count()))) as $surplus) {
                TaskVm::query()->whereKey($surplus->id)->pool()->where('state', TaskVmState::Warm)->update(['state' => TaskVmState::Destroying]);
            }

            $missing = min(-$excess, $free[$node->id] ?? 0);
            if ($missing <= 0) {
                continue;
            }
            $until = self::backoff($node->id)['until'];
            if ($until > now()->getTimestamp()) {
                Log::warning('The task VM pool of a host waits after a failed warm VM.', ['node_id' => $node->id, 'missing' => $missing, 'retry_at' => date(DATE_ATOM, $until)]);

                continue;
            }
            for ($index = 0; $index < $missing; $index++) {
                $created[] = $this->allocate->record(null, $node->id)->id;
            }
        }

        return $created;
    }

    /**
     * Pool VMs on a host that is no longer configured, or whose Node is not active, cannot be destroyed: destroy
     * needs the host's settings and SSH. Each run logs them until the host comes back or its entry returns.
     *
     * @param  list<int>  $managed
     */
    private function reportUnmanaged(array $managed): void
    {
        $stranded = TaskVm::query()->live()->pool()->whereNotIn('host_node_id', $managed)->get(['id', 'name', 'host_node_id']);
        foreach ($stranded as $vm) {
            Log::error('A pool task VM is on a host that is not an active configured host; the pool cannot drain it.', ['task_vm' => $vm->name, 'node_id' => $vm->host_node_id]);
        }
    }

    /** @return array{failures: int, until: int} */
    private static function backoff(int $hostNodeId): array
    {
        $backoff = Cache::get(self::backoffKey($hostNodeId));

        return [
            'failures' => is_array($backoff) && is_int($backoff['failures'] ?? null) ? $backoff['failures'] : 0,
            'until' => is_array($backoff) && is_int($backoff['until'] ?? null) ? $backoff['until'] : 0,
        ];
    }

    private static function backoffKey(int $hostNodeId): string
    {
        return 'task-vms:pool-backoff:'.$hostNodeId;
    }
}
