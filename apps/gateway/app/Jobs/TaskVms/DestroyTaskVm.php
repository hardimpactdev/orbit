<?php

declare(strict_types=1);

namespace App\Jobs\TaskVms;

use App\Actions\Nodes\RemoveNodeAction;
use App\Actions\Tasks\RemoveTaskWorkspaceAction;
use App\Actions\TaskVms\DeleteTaskVmAction;
use App\Actions\TaskVms\WakeTaskVmAction;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskFinalReview;
use App\Domain\TaskVms\TaskVmError;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmSettings;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\TaskVms\TaskVmGuest;
use App\Infrastructure\TaskVms\TaskVmWorker;
use App\Models\Node;
use App\Models\Task;
use App\Models\TaskVm;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Throwable;

/**
 * Destroys a task VM after its workspace is gone: `destroying`, model key revoked, VM deleted, the Node
 * removed offline, and only then `destroyed`. Workspace removal of a task VM group deletes the VM itself
 * ({@see DeleteTaskVmAction}) and queues this job for the rest. A row that is not `destroyed` keeps its Node a task VM
 * Node, so Pi never falls back to the Gateway-wide token. A failed step stays on the row as its error,
 * and the job runs again until it succeeds.
 *
 * A VM that died before its workspace could be removed leaves the workspace behind: removal needs SSH to
 * the VM. Once that removal has failed for an ended group and the VM is absent or stays stopped, the job
 * starts a stopped ready VM to preserve unpublished reviewed work. Only a recorded failed row gives up that work:
 * the host sweep confirms missing VMs, and resume fails a VM that cannot start. The lost SHA and failure stay in the
 * group's evidence and assistance. External groups keep the evidence without raising assistance and qualify for
 * cleanup once ended. Failed is final; destruction never retries its wake.
 *
 * The unique lock has no expiry, so the tick never queues a second copy while one waits or runs.
 */
final class DestroyTaskVm implements ShouldBeUnique, ShouldQueue
{
    use TaskVmRowJob;

    public int $tries = 0;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public int $timeout = 900;

    public function handle(): void
    {
        $vm = TaskVm::query()->find($this->taskVmId);
        if (! $vm instanceof TaskVm || $vm->state === TaskVmState::Destroyed) {
            return;
        }
        try {
            app(TaskVmWorker::class)->stopVm($vm);
        } catch (ResourceOperationException) {
            // A worker that cannot be stopped must reach provider deletion, even past preservation waits.
            $vm->update(['state' => TaskVmState::Destroying]);
        }
        // Only the group's own workspace holds destroy back. Any other Instance on the Node lives in the VM and goes
        // with it.
        $group = $vm->group;
        $workspace = $vm->node_id !== null && $group instanceof Task && $group->workspace()->where('node_id', $vm->node_id)->exists();
        $lostCommit = null;
        if ($workspace) {
            if (! app(WakeTaskVmAction::class)->forVm($vm)) {
                return;
            }
            $commit = TaskFinalReview::reconcileRemovalPush($group);
            // Tick groups require a removal failure; ended external groups never raise that assistance.
            if ($vm->state !== TaskVmState::Destroying) {
                if (! Task::topLevel()->whereKey($group->id)->workspaceDestructionRetry()->exists()) {
                    return;
                }
                if ($vm->state !== TaskVmState::Failed) {
                    if ($commit !== null && $vm->hostSweepWaitReason() !== null && $this->hostSweepPause($vm) !== '') {
                        $this->waitForHostSweep($vm, $group, $commit);

                        return;
                    }
                    $observed = app(TaskVmGuest::class)->observeSettled($vm);
                    if ($observed !== null && $vm->hostSweepWaitReason() !== null) {
                        $vm->update(['error_code' => null, 'error_message' => null]);
                    }
                    if ($commit !== null) {
                        if ($observed !== null && ! $observed->running) {
                            app(WakeTaskVmAction::class)->forVm($vm, stopped: true);
                        } elseif ($observed === null) {
                            $this->waitForHostSweep($vm, $group, $commit);
                        }

                        // A missing ready VM waits for the host sweep's confirmed failure, not a single read.
                        return;
                    }
                    if ($observed?->running) {
                        return;
                    }
                }
            }
            $lostCommit = $commit;
        }

        app(DeleteTaskVmAction::class)->execute($vm);
        if ($workspace && $lostCommit === null) {
            app(RemoveTaskWorkspaceAction::class)->finishRemoval($group);
        }

        try {
            $node = $vm->node()->first();
            if ($node instanceof Node) {
                // The VM is gone, so the Node is unreachable: offline removal sheds its role and forgets its Processes
                // without probing the dead address.
                app(RemoveNodeAction::class)->execute($node, $this->gateway(), offline: true, force: true, deleted: true);
            }
            $vm->update(['state' => TaskVmState::Destroyed, 'destroyed_at' => now(), 'pi_token' => null]);
        } catch (Throwable $exception) {
            $vm->recordError($exception);

            throw $exception;
        }
    }

    /** Owns both the recorded wait and its assistance; removal reads the wait without retrying its push. */
    private function waitForHostSweep(TaskVm $vm, Task $group, string $commit): void
    {
        $reason = "Task VM [{$vm->name}] was not found on its host. Reviewed commit [{$commit}] has not been pushed. Workspace removal waits for the host sweep to confirm loss.".$this->hostSweepPause($vm);
        if ($vm->hostSweepWaitReason() !== $reason) {
            $vm->update(['error_code' => TaskVmError::AwaitingHostSweep->value, 'error_message' => $reason]);
        }
    }

    private function hostSweepPause(TaskVm $vm): string
    {
        if ($vm->provider->needsHostNode()) {
            if (! TaskVmSettings::scheduled()->enabled) {
                return ' The Incus host sweep is paused because task VMs are disabled.';
            } elseif ($vm->incusHostNode()->status !== LifecycleStatus::Active) {
                return " The Incus host sweep is paused because host [{$vm->incusHostNode()->name}] is inactive.";
            }
        }

        return '';
    }

    private function gateway(): Node
    {
        return Node::query()->where('status', LifecycleStatus::Active)
            ->whereHas('roles', static fn ($query) => $query->where('role', RoleName::Gateway)->where('status', LifecycleStatus::Active))
            ->first() ?? throw new TaskVmException(TaskVmError::JobFailed, 'No active Gateway Node can remove the task VM Node.', 500);
    }
}
