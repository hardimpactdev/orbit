<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Models\Instance;
use App\Models\Operation;
use Throwable;

final readonly class TaskVmWorker
{
    public function __construct(private AgentCommandRunner $agents, private TaskWorkerEnrollment $enrollment) {}

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
        try {
            $result = $this->agents->run($instance->node, 'swamp.start-worker', [
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

    public function forget(Operation $operation): void
    {
        $worker = $operation->input['worker'] ?? null;
        $reference = is_array($worker) ? ($worker['enrollment_reference'] ?? null) : null;
        if (is_string($reference)) {
            $this->enrollment->forget($reference);
        }
    }
}
