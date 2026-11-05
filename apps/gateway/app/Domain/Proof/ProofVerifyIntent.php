<?php

declare(strict_types=1);

namespace App\Domain\Proof;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskGroupStatus;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Task;
use App\Models\TaskVm;

/** Admission only. Orbit's own Swamp runs the cases of one command in the task VM of the group, over official SSH. */
final readonly class ProofVerifyIntent
{
    public function __construct(private StartOperationAction $start, private OperationIdempotency $idempotency) {}

    /** @param string $file the cases file of the command, relative to the checkout of the task VM */
    public function start(Task $group, string $command, string $file, string $caller, ?string $request): Operation
    {
        if (($existing = $this->idempotency->replay('proof.verify')) !== null) {
            return $existing;
        }
        $group->refresh()->load('taskable');
        if (in_array($group->status, TaskGroupStatus::endedStatuses(), true)) {
            throw new ResourceOperationException('tasks.group_closed', 'This task group has ended.', 409);
        }
        $instance = $group->taskable;
        if (! $instance instanceof Instance || $instance->task_workspace_owner_id !== $group->id || $instance->project_id !== $group->project_id
            || TaskVm::forGroup($group)?->node_id !== $instance->node_id || $instance->checkout_path === '') {
            throw new ResourceOperationException('tasks.no_workspace', 'The group requires its owned task VM workspace.', 409);
        }

        return $this->start->execute('proof.verify', 'task_group', $group->id, $instance->node_id, [
            'group_id' => $group->id, 'instance_id' => $instance->id, 'node_id' => $instance->node_id, 'command' => $command, 'file' => $file,
            'ssh_execution' => app(SwampSshExecutionInput::class)->forNode($instance->node), 'checkout_path' => $instance->checkout_path,
        ], $caller, $request);
    }
}
