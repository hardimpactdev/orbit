<?php

declare(strict_types=1);

namespace App\Actions\TaskVms;

use App\Domain\TaskVms\TaskVmCapacity;
use App\Domain\TaskVms\TaskVmError;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmPlacementTarget;
use App\Domain\TaskVms\TaskVmPoolBackoff;
use App\Domain\TaskVms\TaskVmSettings;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\TaskVms\IncusHost;
use App\Infrastructure\TaskVms\IncusTaskVmBaseImage;
use App\Jobs\TaskVms\DestroyTaskVm;
use App\Jobs\TaskVms\ProvisionTaskVm;
use App\Models\Node;
use App\Models\TaskVm;
use Illuminate\Contracts\Database\ConcurrencyErrorDetector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Keeps each task VM host's pool at its `warm_vms`. One run does three things, in this order:
 *
 * 1. It reads each host's base image and the image of each instance, records the image of each warm VM, and
 *    destroys a warm VM whose image is no longer `orbit-task-base` or whose instance is gone.
 * 2. Inside the placement transaction, it destroys failed pool VMs and warm VMs over the target, and records new pool
 *    rows up to the target within the host's free slots. The target is 0 while task VMs are disabled, so the
 *    pool drains. Pool VMs that are still starting are never cut off; a later run destroys any excess.
 * 3. It queues `ProvisionTaskVm` for each new row, and then `DestroyTaskVm` for every pool row that is `destroying`.
 *    A `destroying` row counts against `max_vms` until it is `destroyed`, so a new row exists only within the
 *    host's budget, and the new VM can start before the old one goes.
 *
 * A pool row still `provisioning` an hour after it was recorded has lost its job. The run fails it loudly, and
 * destroys it with the failed rows.
 *
 * A host whose pool VM failed waits before it creates another ({@see TaskVmPoolBackoff}). Each skipped refill logs a
 * warning.
 */
final readonly class RefillTaskVmPoolAction
{
    /** Minutes a pool row may stay `provisioning` before the refill fails it. */
    public const int StartMinutes = 60;

    public function __construct(
        private TaskVmSettings $settings,
        private TaskVmCapacity $capacity,
        private RecordTaskVmAction $record,
        private TaskVmPoolBackoff $backoff,
        private IncusHost $incus,
        private IncusTaskVmBaseImage $images,
    ) {}

    /** @return array{created: int, destroying: int} */
    public function execute(): array
    {
        $hosts = $this->capacity->activeHosts();
        foreach ($hosts as [$host, $node]) {
            $this->replaceStale($host, $node);
        }

        try {
            $created = DB::transaction(fn (): array => $this->balance($hosts));
        } catch (Throwable $exception) {
            if (! app(ConcurrencyErrorDetector::class)->causedByConcurrencyError($exception)) {
                throw $exception;
            }
            Log::warning('The task VM pool refill waits for another placement; the next run tries again.');
            $created = [];
        }
        $this->reportUnmanaged(array_map(static fn (array $entry): int => $entry[0]->nodeId, $hosts));

        // New VMs first: the `task-vms` queue runs one job at a time, and a destroy waits on the Node removal. The order
        // cannot overfill a host, because a `destroying` row holds its slot until it is `destroyed`.
        foreach ($created as $id) {
            ProvisionTaskVm::dispatch($id);
        }
        $destroying = TaskVm::query()->pool()->where('state', TaskVmState::Destroying)->get(['id']);
        foreach ($destroying as $vm) {
            DestroyTaskVm::dispatch($vm->id);
        }

        return ['created' => count($created), 'destroying' => $destroying->count()];
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
            $current = $this->incus->aliasTarget($host, $node, $host->imageAlias, 120.0);
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
        // A failed VM holds no slot, but a destroying one does, so the failed pool VMs move first.
        foreach ($hosts as [, $node]) {
            $this->failStuck($node);
            TaskVm::query()->pool()->where('host_node_id', $node->id)->where('state', TaskVmState::Failed)->update(['state' => TaskVmState::Destroying]);
        }
        // Read again under the lock, so a Node that stopped being active meanwhile gets no new VM.
        $free = $this->capacity->freeSlots($this->capacity->activeHosts());
        $created = [];
        foreach ($hosts as [$host, $node]) {
            $pool = TaskVm::query()->live()->pool()->where('host_node_id', $node->id)->orderBy('id')->get();

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
            $until = $this->backoff->until($node->id);
            if ($until > now()->getTimestamp()) {
                Log::warning('The task VM pool of a host waits after a failed warm VM.', ['node_id' => $node->id, 'missing' => $missing, 'retry_at' => date(DATE_ATOM, $until)]);

                continue;
            }
            for ($index = 0; $index < $missing; $index++) {
                $created[] = $this->record->execute(null, TaskVmPlacementTarget::incusHost($node->id))->id;
            }
        }

        return $created;
    }

    /**
     * A pool row still `provisioning` an hour after its row was recorded has lost its job: the boot limit is 10
     * minutes from launch, and the slowest queue wait is a nightly refresh of 25 minutes. It becomes `failed`,
     * which logs an error and makes the host wait, and the same run destroys it, so the slot comes back.
     */
    private function failStuck(Node $node): void
    {
        $stuck = TaskVm::query()->pool()->where('state', TaskVmState::Provisioning)->where('host_node_id', $node->id)
            ->where('created_at', '<=', now()->subMinutes(self::StartMinutes))->get();
        foreach ($stuck as $vm) {
            $message = "Pool task VM [{$vm->name}] was not warm ".self::StartMinutes.' minutes after its row was recorded.';
            $failed = TaskVm::query()->whereKey($vm->id)->pool()->where('state', TaskVmState::Provisioning)
                ->update(['state' => TaskVmState::Failed, 'error_code' => TaskVmError::PoolStartTimeout->value, 'error_message' => $message]);
            if ($failed === 1) {
                $this->backoff->failed($vm, TaskVmError::PoolStartTimeout->value, $message);
            }
        }
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
}
