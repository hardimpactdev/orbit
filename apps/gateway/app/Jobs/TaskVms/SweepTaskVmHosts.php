<?php

declare(strict_types=1);

namespace App\Jobs\TaskVms;

use App\Actions\TaskVms\FailTaskVmAction;
use App\Domain\TaskVms\TaskVmCapacity;
use App\Domain\TaskVms\TaskVmError;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmFailedException;
use App\Domain\TaskVms\TaskVmProviderKind;
use App\Domain\TaskVms\TaskVmSettings;
use App\Domain\TaskVms\TaskVmState;
use App\Domain\TaskVms\TaskVmUpCloud;
use App\Infrastructure\TaskVms\TaskVmHostAudit;
use App\Infrastructure\TaskVms\TaskVmWorkspaceTemplates;
use App\Infrastructure\TaskVms\UpCloudServers;
use App\Infrastructure\TaskVms\UpCloudTaskVmImageBuilder;
use App\Models\Node;
use App\Models\TaskVm;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Every 10 minutes, while task VMs are enabled: deletes orphaned `tvm-<number>` VMs in each configured host's Incus project, then the workspace
 * clones `tvm-<number>-workspace` that no live row owns, and fails the
 * `warm`, `ready`, or `parked` rows whose VM is gone on two sweeps in a row, so their groups ask for assistance. A
 * failed warm VM has no group: the pool refill destroys it. The
 * first sweep that misses a VM only marks it in the cache; a sweep that finds the VM clears the mark. It changes only the
 * host's configured project. While UpCloud is configured, also with task VMs or the overflow off, it does the same for
 * the servers with the task VM label there, and deletes base image build servers and unverified templates older than
 * two hours, except those of a build that is marked as running. On every Incus host and for every `tvm-<number>` Node, it also logs a warning per stray
 * ({@see TaskVmHostAudit::strays()}, {@see TaskVmHostAudit::strayNodes()}) and deletes none. The `task-vms` queue runs one job at a time, so no
 * task VM job creates or deletes a VM while the sweep runs. A failed
 * host is logged, and the other hosts still run.
 */
final class SweepTaskVmHosts implements ShouldBeUnique, ShouldQueue
{
    use TaskVmJob;

    public int $tries = 1;

    public int $timeout = 600;

    public int $uniqueFor = 900;

    public function __construct()
    {
        $this->onTaskVmQueue();
    }

    public function handle(TaskVmSettings $settings, TaskVmCapacity $capacity, TaskVmHostAudit $audit, TaskVmWorkspaceTemplates $templates, UpCloudServers $servers, UpCloudTaskVmImageBuilder $images): void
    {
        $failures = [];
        foreach ($settings->enabled ? $capacity->activeHosts() : [] as [$host, $node]) {
            try {
                $found = $audit->audit($host, $node);
                foreach ($found['orphaned'] as $name) {
                    $audit->deleteOrphan($host, $node, $name);
                    Log::warning('Deleted an orphaned task VM.', ['node_id' => $node->id, 'name' => $name]);
                }
                foreach ($templates->sweepClones($host, $node) as $name) {
                    Log::warning('Deleted an orphaned task VM workspace clone.', ['node_id' => $node->id, 'volume' => $name]);
                }
                $this->misses(TaskVm::query()->live()->where('host_node_id', $node->id), $found['missing']);
                $this->reportStrays($audit->strays($host, $node), $node);
            } catch (Throwable $exception) {
                Log::error('The task VM host sweep failed.', ['node_id' => $node->id, 'error' => $exception->getMessage()]);
                $failures[] = "Node [{$node->name}]: ".TaskVmException::codeOf($exception);
            }
        }
        foreach ($settings->enabled ? TaskVmHostAudit::strayNodes() : [] as $stray) {
            Log::warning('A task VM Node has no live task VM record.', ['node_id' => $stray->id, 'name' => $stray->name]);
        }
        if ($settings->upcloud !== null) {
            try {
                $this->sweepUpCloud($servers);
                // A build that was killed leaves billed servers; a build holds its lock for at most an hour.
                $images->sweep(2 * 3600);
            } catch (Throwable $exception) {
                Log::error('The UpCloud task VM sweep failed.', ['error' => $exception->getMessage()]);
                $failures[] = 'UpCloud: '.TaskVmException::codeOf($exception);
            }
        }

        if ($failures !== []) {
            throw new TaskVmException(TaskVmError::HostCommandFailed, 'The task VM host sweep failed on '.implode(', ', $failures).'.', 502);
        }
    }

    /**
     * Logs one warning per stray, every sweep. Nothing deletes a stray: the operator decides.
     *
     * @param  array{instances: list<string>, volumes: list<string>, records: list<TaskVm>}  $strays
     */
    private function reportStrays(array $strays, Node $node): void
    {
        foreach ($strays['instances'] as $instance) {
            Log::warning('A task VM host has an Incus instance that Orbit does not own.', ['node_id' => $node->id, 'instance' => $instance]);
        }
        foreach ($strays['volumes'] as $volume) {
            Log::warning('A task VM host has an Incus custom volume that Orbit does not own.', ['node_id' => $node->id, 'volume' => $volume]);
        }
        foreach ($strays['records'] as $vm) {
            Log::warning('A task VM is ready or parked without an open group.', ['task_vm_id' => $vm->id, 'name' => $vm->name, 'state' => $vm->state->value, 'group_id' => $vm->group_id]);
        }
    }

    /**
     * Deletes each server with the task VM label whose name is `tvm-<label value>` and whose row is not live, and
     * counts the misses of live UpCloud rows whose server is gone. It runs while UpCloud is configured, also with the
     * overflow switched off, so no server that Orbit created keeps costing money.
     */
    private function sweepUpCloud(UpCloudServers $servers): void
    {
        $labelled = $servers->labelled(TaskVmUpCloud::Label);
        $live = TaskVm::query()->live()->where('provider', TaskVmProviderKind::UpCloud)->pluck('name')->all();
        foreach ($labelled as $server) {
            if ($server['title'] === 'tvm-'.$server['value'] && preg_match('/\Atvm-[0-9]+\z/D', $server['title']) === 1 && ! in_array($server['title'], $live, true)) {
                $servers->delete($server['title'], TaskVmUpCloud::Label, $server['value'], $server['uuid']);
                Log::warning('Deleted an orphaned UpCloud task VM.', ['name' => $server['title'], 'server' => $server['uuid']]);
            }
        }
        $names = array_column($labelled, 'title');
        $rows = TaskVm::query()->live()->where('provider', TaskVmProviderKind::UpCloud);
        $this->misses($rows, array_values((clone $rows)->get()->filter(
            static fn (TaskVm $vm): bool => in_array($vm->state, [TaskVmState::Warm, TaskVmState::Ready, TaskVmState::Parked], true) && ! in_array($vm->name, $names, true),
        )->all()));
    }

    /**
     * Fails a missing VM on its second sweep in a row; the first only marks it. A VM that is found clears its mark.
     *
     * @param  Builder<TaskVm>  $live  the live rows the sweep looked at
     * @param  list<TaskVm>  $missing
     */
    private function misses(Builder $live, array $missing): void
    {
        $ids = array_map(static fn (TaskVm $vm): int => $vm->id, $missing);
        foreach ($live->whereNotIn('id', $ids)->get(['id']) as $present) {
            Cache::forget(self::missingKey($present->id));
        }
        foreach ($missing as $vm) {
            if (Cache::has(self::missingKey($vm->id))) {
                $this->fail($vm);
                Cache::forget(self::missingKey($vm->id));
            } else {
                Cache::put(self::missingKey($vm->id), now()->getTimestamp(), 3600);
                Log::warning('A task VM was not found on its host; the next sweep fails it if it is still gone.', ['task_vm_id' => $vm->id, 'name' => $vm->name]);
            }
        }
    }

    private static function missingKey(int $taskVmId): string
    {
        return 'task-vms:sweep-missing:'.$taskVmId;
    }

    /** Only a row that is still `warm`, `ready`, or `parked` fails, so a destroy or a claim that started meanwhile wins. */
    private function fail(TaskVm $vm): void
    {
        $message = "Task VM [{$vm->name}] is gone from its host.";
        if (app(FailTaskVmAction::class)->execute($vm, [TaskVmState::Warm, TaskVmState::Ready, TaskVmState::Parked], TaskVmError::VmMissing->value, $message, TaskVmFailedException::stoppedReason(TaskVmError::VmMissing->value, $message))) {
            Log::warning('A task VM is gone from its host.', ['task_vm_id' => $vm->id, 'name' => $vm->name]);
        }
    }
}
