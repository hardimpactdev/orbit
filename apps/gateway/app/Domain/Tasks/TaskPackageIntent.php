<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Actions\Operations\StartOperationAction;
use App\Data\Tasks\StartTaskPackageData;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Task;
use App\Models\TaskVm;

/**
 * Admits the install of one private package in a task workspace. It records the request and names the vault keys of the dependency
 * configuration; the run mints the token ({@see TaskGitHubTokens}) and Swamp installs the package over SSH from Node 209.
 */
final readonly class TaskPackageIntent
{
    public function __construct(private StartOperationAction $start, private OperationIdempotency $idempotency) {}

    public function start(Task $group, StartTaskPackageData $input, string $caller, ?string $request): Operation
    {
        if (($existing = $this->idempotency->replay('tasks.package')) !== null) {
            return $existing;
        }

        return app(TaskExecutionLock::class)->synchronized($group->id, function () use ($group, $input, $caller, $request): Operation {
            if (($existing = $this->idempotency->replay('tasks.package')) !== null) {
                return $existing;
            }
            $group->refresh()->load('taskable', 'project');
            if (Operation::query()->where('target_type', 'task_group')->where('target_id', $group->id)
                ->whereNotIn('status', OperationStatus::terminalValues())->exists()) {
                throw new ResourceOperationException('operation.target_busy', 'Another Task Operation owns this group.', 409);
            }
            if (in_array($group->status, TaskGroupStatus::endedStatuses(), true)) {
                throw new ResourceOperationException('tasks.group_closed', 'This task group has ended.', 409);
            }
            $instance = $group->taskable;
            $vm = TaskVm::forGroup($group);
            if (! $instance instanceof Instance || ! $vm instanceof TaskVm || $instance->task_workspace_owner_id !== $group->id
                || $instance->project_id !== $group->project_id || $vm->node_id !== $instance->node_id || $instance->checkout_path === '') {
                throw new ResourceOperationException('tasks.no_workspace', 'The group requires its owned task VM workspace.', 409);
            }
            $instance->loadMissing('node');

            return $this->start->execute('tasks.package', 'task_group', $group->id, $instance->node_id, [
                ...$input->toArray(), 'group_id' => $group->id, 'instance_id' => $instance->id, 'node_id' => $instance->node_id,
                'project_id' => $group->project_id, 'checkout_path' => $instance->checkout_path, 'user' => $instance->node->user,
                'ssh_execution' => app(SwampSshExecutionInput::class)->forNode($instance->node),
                // The keys are the ones the provision of this VM used; the group runs one task at a time, and each mint overwrites them.
                'dependency_auth' => TaskGitHubTokens::dependencyAuth($vm),
            ], $caller, $request, lockKey: OperationStore::lockKey('task_group', $group->id));
        });
    }
}
