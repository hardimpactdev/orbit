<?php

declare(strict_types=1);

namespace App\Jobs\TaskVms;

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\AssistanceKind;
use App\Domain\Tasks\TaskAssistance;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmSettings;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\TaskVms\TaskVmHostAudit;
use App\Infrastructure\TaskVms\TaskVmWorkspaceTemplates;
use App\Models\Node;
use App\Models\Task;
use App\Models\TaskVm;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Every 10 minutes: deletes orphaned `tvm-<number>` VMs in each configured host's Incus project, then the workspace
 * clones `tvm-<number>-workspace` that no live row owns, and fails the
 * `warm`, `ready`, or `parked` rows whose VM is gone on two sweeps in a row, so their groups ask for assistance. A
 * failed warm VM has no group: the pool refill destroys it. The
 * first sweep that misses a VM only marks it in the cache; a sweep that finds the VM clears the mark. It reads and changes only the
 * host's configured project. The worker runs one job at a time, so no task VM job creates or deletes a VM while
 * the sweep runs. A failed host is logged, and the other hosts still run.
 */
final class SweepTaskVmHosts implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const string MissingCode = 'task_vm.vm_missing';

    public int $tries = 1;

    public int $timeout = 600;

    public int $uniqueFor = 900;

    public function __construct()
    {
        $this->onConnection('task-vms')->onQueue('task-vms');
    }

    public function handle(TaskVmSettings $settings, TaskVmHostAudit $audit, TaskVmWorkspaceTemplates $templates): void
    {
        $failures = [];
        foreach ($settings->hosts as $host) {
            $node = Node::query()->whereKey($host->nodeId)->where('status', LifecycleStatus::Active)->first();
            if (! $node instanceof Node) {
                continue;
            }
            try {
                $found = $audit->audit($host, $node);
                foreach ($found['orphaned'] as $name) {
                    $audit->deleteOrphan($host, $node, $name);
                    Log::warning('Deleted an orphaned task VM.', ['node_id' => $node->id, 'name' => $name]);
                }
                foreach ($templates->sweepClones($host, $node) as $name) {
                    Log::warning('Deleted an orphaned task VM workspace clone.', ['node_id' => $node->id, 'volume' => $name]);
                }
                $missing = array_map(static fn (TaskVm $vm): int => $vm->id, $found['missing']);
                foreach (TaskVm::query()->live()->where('host_node_id', $node->id)->whereNotIn('id', $missing)->get(['id']) as $present) {
                    Cache::forget(self::missingKey($present->id));
                }
                foreach ($found['missing'] as $vm) {
                    if (Cache::has(self::missingKey($vm->id))) {
                        $this->fail($vm);
                        Cache::forget(self::missingKey($vm->id));
                    } else {
                        Cache::put(self::missingKey($vm->id), now()->getTimestamp(), 3600);
                        Log::warning('A task VM was not found on its host; the next sweep fails it if it is still gone.', ['task_vm_id' => $vm->id, 'name' => $vm->name]);
                    }
                }
            } catch (Throwable $exception) {
                Log::error('The task VM host sweep failed.', ['node_id' => $node->id, 'error' => $exception->getMessage()]);
                $failures[] = "Node [{$node->name}]: ".TaskVmException::codeOf($exception);
            }
        }

        if ($failures !== []) {
            throw new TaskVmException('task_vm.host_command_failed', 'The task VM host sweep failed on '.implode(', ', $failures).'.', 502);
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
        $failed = TaskVm::query()->whereKey($vm->id)
            ->whereIn('state', [TaskVmState::Warm, TaskVmState::Ready, TaskVmState::Parked])
            ->update(['state' => TaskVmState::Failed, 'error_code' => self::MissingCode, 'error_message' => $message]);
        $group = $vm->group;
        if ($failed === 1) {
            Log::warning('A task VM is gone from its host.', ['task_vm_id' => $vm->id, 'name' => $vm->name]);
        }
        if ($failed === 1 && $group instanceof Task) {
            TaskAssistance::apply($group, AssistanceKind::Failure, null, ProvisionTaskVm::FailedReasonPrefix.self::MissingCode.": {$message} Cancel or complete the group to destroy the VM.", replaceFailure: true);
        }
    }
}
