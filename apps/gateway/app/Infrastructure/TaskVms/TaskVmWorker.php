<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskExecutionLock;
use App\Domain\TaskVms\TaskVmProvider;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Task;
use App\Models\TaskVm;
use Throwable;

final readonly class TaskVmWorker
{
    public function __construct(private AgentCommandRunner $agents, private TaskWorkerEnrollment $enrollment, private TaskExecutionLock $execution) {}

    /** Check before capacity allocation or resume, while the startup step has not run. */
    public function requireEnrollment(Operation $operation): void
    {
        $worker = $operation->input['worker'] ?? null;
        if (is_array($worker) && (! is_string($worker['enrollment_reference'] ?? null) || $this->enrollment->read($worker['enrollment_reference']) === null)) {
            throw new ResourceOperationException('tasks.worker_enrollment_expired', 'The worker startup credential is missing or expired.');
        }
    }

    public function start(Operation $operation, Instance $instance): void
    {
        $worker = $operation->input['worker'] ?? null;
        if (! is_array($worker)) {
            return;
        }
        $reference = $worker['enrollment_reference'] ?? null;
        if (! is_string($reference)) {
            throw new ResourceOperationException('tasks.worker_enrollment_expired', 'The worker startup credential is missing or expired.');
        }
        $vm = TaskVm::forNode($instance->node_id);
        if (! $vm instanceof TaskVm || $vm->group_id !== $operation->target_id || $vm->state !== TaskVmState::Ready) {
            throw new ResourceOperationException('tasks.no_workspace', 'The group has no task VM workspace.', 409);
        }
        // The command might start before a transport failure. Cleanup must still stop that worker.
        $vm->update(['worker_stop_required' => true]);
        try {
            $result = $this->agents->run($instance->node, 'swamp.start-worker', [
                'enrollment_id' => $reference,
                'workspace' => $instance->checkout_path,
                'orchestrator_url' => $worker['orchestrator_url'],
                'labels' => (object) ($worker['labels'] ?? []),
                'enrollment_token' => $this->enrollment->read($reference),
            ]);
        } catch (Throwable) {
            // Remote diagnostics may contain credentials; only this fixed error crosses the boundary.
            throw new ResourceOperationException('tasks.worker_start_failed', 'The task VM worker could not be started.');
        } finally {
            $this->forget($operation);
        }
        if (($result['started'] ?? false) !== true) {
            throw new ResourceOperationException('tasks.worker_enrollment_expired', 'The worker startup credential is missing or expired.');
        }
    }

    public function stopGroup(Task $group): void
    {
        $this->execution->synchronized($group->id, function () use ($group): void {
            $vm = TaskVm::forGroup($group);
            if ($vm instanceof TaskVm) {
                $this->stopVm($vm);
            }
        });
    }

    /** Stop before any preservation wait or destruction retry can leave the VM alive. */
    public function stopVm(TaskVm $vm): void
    {
        $vm->refresh();
        if (! $vm->worker_stop_required) {
            return;
        }
        try {
            $node = $vm->node;
            if ($node === null || ($this->agents->run($node, 'swamp.stop-worker')['stopped'] ?? false) !== true) {
                throw new \RuntimeException;
            }
        } catch (Throwable) {
            // No response does not prove the worker stopped. Only a provider-confirmed stopped or absent VM does.
            try {
                $observed = app(TaskVmProvider::class)->observe($vm);
                if ($observed?->running === true) {
                    throw new \RuntimeException;
                }
            } catch (Throwable) {
                throw new ResourceOperationException('tasks.worker_stop_failed', 'The task VM worker could not be stopped.', 502);
            }
        }
        $vm->update(['worker_stop_required' => false]);
    }

    public function forget(Operation $operation): void
    {
        $worker = $operation->input['worker'] ?? null;
        $reference = is_array($worker) ? ($worker['enrollment_reference'] ?? null) : null;
        if (is_string($reference)) {
            $this->enrollment->forget($reference);
        }
    }
}
