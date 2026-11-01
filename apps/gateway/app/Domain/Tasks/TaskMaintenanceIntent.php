<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Actions\Tasks\RequireTasksExtensionAction;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Task;
use App\Models\TaskVm;
use App\Support\ValidatedData;

/** Full immutable maintenance input; native SSH owns package execution. */
final readonly class TaskMaintenanceIntent
{
    public function start(Task $group): Operation
    {
        app(RequireTasksExtensionAction::class)->execute();
        $group->refresh()->load(['taskable', 'project']);
        $instance = $group->taskable;
        $vm = TaskVm::forGroup($group);
        $maintenance = $group->maintenance;
        $configured = GitHubRepository::fromOrigin(ValidatedData::string(ValidatedData::object($maintenance['project'] ?? [])['repository'] ?? ''));
        $current = GitHubRepository::fromOrigin($group->project->repository_url);
        if ($group->status !== TaskGroupStatus::Running || ! is_array($maintenance)
            || ! $instance instanceof Instance || ! $vm instanceof TaskVm || $vm->state !== TaskVmState::Ready || $vm->node_id !== $instance->node_id
            || $instance->task_workspace_owner_id !== $group->id || $instance->project_id !== $group->project_id
            || $configured === null || $current === null || strtolower($configured->owner.'/'.$configured->name) !== strtolower($current->owner.'/'.$current->name)
            || ! is_string($instance->starting_commit) || ! preg_match('/\A(?:[a-f0-9]{40}|[a-f0-9]{64})\z/D', $instance->starting_commit)
            || (isset($maintenance['source_commit']) && $maintenance['source_commit'] !== $instance->starting_commit)) {
            throw new ResourceOperationException('tasks.maintenance_refused', 'Maintenance requires its unchanged captured Project, source and owned workspace.', 409);
        }
        $child = $group->tasks()->orderBy('position')->firstOrFail();
        $intent = [
            'group_id' => $group->id, 'project_id' => $group->project_id, 'instance_id' => $instance->id,
            'base' => $instance->starting_commit, 'node_id' => $instance->node_id, 'task_vm_id' => $vm->id, 'subtask' => $child->id, 'checkout_path' => $instance->checkout_path,
            'ssh_execution' => app(SwampSshExecutionInput::class)->forNode($instance->node), 'maintenance' => $maintenance,
        ];

        return app(TaskOperations::class)->start($group, 'tasks.maintenance', [], static function (Task $current) use ($instance): void {
            if ($current->status !== TaskGroupStatus::Running || $current->taskable_id !== $instance->id) {
                throw new ResourceOperationException('tasks.maintenance_refused', 'The maintenance workspace changed before admission.', 409);
            }
        }, 'project-maintenance', null, static function () use ($child, $intent): array {
            $child->update(['status' => TaskStatus::Running]);

            return $intent;
        });
    }
}
