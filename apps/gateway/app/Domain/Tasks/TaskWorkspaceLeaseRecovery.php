<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\RemotePrograms\RemoteProgramRunner;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\RemotePrograms\RemoteProgramCatalog;
use App\Infrastructure\Tasks\RemoteWorkspaceOperationJournal;
use App\Models\Activity;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Task;
use App\Models\TaskVm;
use App\Models\TaskWorkspaceLeaseRecord;
use App\Models\TaskWorkspaceOperation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/** Operator recovery ends proven remote ownership, never grants review approval or checkout ownership. */
final readonly class TaskWorkspaceLeaseRecovery
{
    public function __construct(private TaskWorkspaceLease $leases, private RemoteWorkspaceOperationJournal $operations) {}

    /** @return array<string, mixed> */
    public function release(TaskWorkspaceLeaseRecord $lease, string $reason, ?Node $gateway = null): array
    {
        $reason = trim($reason);
        if ($reason === '' || strlen($reason) > 1024) {
            throw new TaskWorkspaceWaiting('A recovery reason between 1 and 1024 bytes is required.');
        }
        $proof = [];
        try {
            $proof = app(TaskExecutionLock::class)->synchronized($lease->group_id, function () use ($lease, $gateway): array {
                $lease->refresh();
                if ($lease->status === 'released') {
                    return ['already_released' => true];
                }
                $group = Task::topLevel()->find($lease->group_id);
                if (! $group instanceof Task || $lease->status !== 'held' || $lease->getAttribute('active_instance_id') !== $lease->instance_id) {
                    throw new TaskWorkspaceWaiting('The exact held workspace lease is unavailable.');
                }
                if (! $lease->getAttribute('recovery_sealed')) {
                    // Existing authoritative check/turn completion may discharge legacy ownership normally.
                    $this->leases->reconcile($group);
                    $lease->refresh();
                    if ($lease->status === 'released') {
                        return ['normal_reconciliation' => true];
                    }
                }
                if ($lease->thread_id !== null || $lease->pid !== null || $lease->getAttribute('dispatch_started_at') !== null) {
                    throw new TaskWorkspaceWaiting('The separate check or agent turn still needs authoritative terminal evidence.');
                }
                if (! $lease->getAttribute('journaled') || ! $this->ownerDead($lease, $gateway)) {
                    throw new TaskWorkspaceWaiting('The recorded Gateway owner is live or unverifiable; no lease was displaced.');
                }
                if ($lease->kind === 'teardown' && TaskVm::query()->where('group_id', $group->id)->where('state', '!=', TaskVmState::Destroyed)->exists()) {
                    throw new TaskWorkspaceWaiting('Provider teardown is not proven Destroyed.');
                }
                app(TaskExecutionLock::class)->synchronized(-$lease->instance_id, static function () use ($lease): void {
                    DB::transaction(static function () use ($lease): void {
                        $current = TaskWorkspaceLeaseRecord::query()->whereKey($lease->id)->lockForUpdate()->firstOrFail();
                        if ($current->status !== 'held' || $current->getAttribute('active_instance_id') !== $lease->instance_id) {
                            throw new TaskWorkspaceWaiting('The recovery target changed before it was sealed.');
                        }
                        $current->update(['recovery_sealed' => true, 'causality_uncertain' => true]);
                    });
                });
                $instance = Instance::query()->find($lease->instance_id);
                $evidence = $this->operations->seal($lease->refresh(), $instance);

                return app(TaskExecutionLock::class)->synchronized(-$lease->instance_id, function () use ($lease, $evidence): array {
                    $current = $lease->fresh();
                    if (! $current instanceof TaskWorkspaceLeaseRecord || $current->status !== 'held'
                        || $current->getAttribute('active_instance_id') !== $lease->instance_id || ! $current->getAttribute('recovery_sealed')) {
                        throw new TaskWorkspaceWaiting('The held recovery target changed during remote verification.');
                    }
                    // The binding seal covers IDs created before or after the endpoint snapshot; the dead
                    // Gateway identity and local admission lock prevent a new local actor from dispatching.
                    TaskWorkspaceOperation::query()->where('lease_id', $lease->id)->where('status', 'pending')->update(['status' => 'finished']);
                    $current->update(['remote_uncertain' => false]);
                    // No after snapshot from a manually recovered interval is causal evidence.
                    $current->update(['active_instance_id' => null, 'status' => 'released', 'released_at' => now(), 'completion_pending' => false]);

                    return $evidence;
                });
            }, wait: false);
        } catch (Throwable $exception) {
            $this->audit($lease, $reason, 'refused', ['exception' => $exception::class]);
            throw $exception;
        }
        $this->audit($lease, $reason, 'released', $proof);

        return $proof;
    }

    private function ownerDead(TaskWorkspaceLeaseRecord $lease, ?Node $gateway): bool
    {
        if ($lease->getAttribute('gateway_host') === gethostname()) {
            return $this->leases->localOwnerDead($lease);
        }
        $host = $lease->getAttribute('gateway_host');
        $boot = $lease->getAttribute('gateway_boot');
        $pid = $lease->getAttribute('gateway_pid');
        $started = $lease->getAttribute('gateway_started');
        if (! $gateway instanceof Node || ! is_string($host) || ! is_string($boot) || ! is_int($pid) || $pid < 1 || ! is_string($started)) {
            return false;
        }
        $result = app(RemoteProgramRunner::class)->run($gateway, app(RemoteProgramCatalog::class)->program('gateway.task-workspace-owner'),
            ['host' => $host, 'boot' => $boot, 'pid' => $pid, 'started' => $started]);

        return $result->outcome === 'inspected' && ($result->data['host'] ?? null) === $host && ($result->data['dead'] ?? null) === true;
    }

    /** @param array<string, mixed> $proof */
    private function audit(TaskWorkspaceLeaseRecord $lease, string $reason, string $result, array $proof): void
    {
        Activity::query()->create([
            'log_name' => 'tasks', 'description' => 'Workspace lease recovery '.$result,
            'subject_type' => TaskWorkspaceLeaseRecord::class, 'subject_id' => $lease->id,
            'properties' => ['lease_id' => $lease->id, 'instance_id' => $lease->instance_id, 'group_id' => $lease->group_id,
                'reason' => $reason, 'result' => $result, 'proof' => $proof],
            'request_id' => (string) Str::uuid(), 'command' => 'tasks:lease:release', 'status' => $result === 'released' ? 'completed' : 'failed',
        ]);
    }
}
