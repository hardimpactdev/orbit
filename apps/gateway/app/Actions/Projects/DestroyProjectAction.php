<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Projects\ProjectRemovalHook;
use App\Domain\Routes\RouteRemovalGuard;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\TaskVms\TaskVmState;
use App\Models\Project;
use App\Models\TaskVm;
use App\Models\TaskWorkspaceLeaseRecord;
use Illuminate\Container\Attributes\Tag;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final readonly class DestroyProjectAction
{
    /** @param iterable<ProjectRemovalHook> $hooks */
    public function __construct(
        #[Tag(ProjectRemovalHook::class)] private iterable $hooks,
        private ?RouteRemovalGuard $routes = null,
        private ?RecordEventBroadcaster $broadcaster = null,
    ) {}

    public function execute(Project $project): Project
    {
        DB::transaction(function () use ($project): void {
            foreach ($this->hooks as $hook) {
                $hook->lock($project);
            }
            if ($project->instances()->exists()) {
                throw new ResourceOperationException(
                    errorCode: 'project.has_instances',
                    message: "Project [{$project->slug}] still has Instances.",
                    status: 409,
                );
            }

            ($this->routes ?? app(RouteRemovalGuard::class))->assertAppRemovable($project);

            if ($project->tasks()->whereNotIn('status', [TaskGroupStatus::Cancelled, TaskGroupStatus::Completed])->exists()) {
                throw new ResourceOperationException(
                    errorCode: 'project.has_task_groups',
                    message: "Project [{$project->slug}] still has task groups.",
                    status: 409,
                );
            }

            $this->removeTaskHistory($project);

            foreach ($this->hooks as $hook) {
                $hook->remove($project);
            }
            $project->delete();
        });

        ($this->broadcaster ?? app(RecordEventBroadcaster::class))->broadcast(
            RecordEventType::ProjectDeleted,
            $project->id,
            ['id' => $project->id, 'name' => $project->name, 'slug' => $project->slug],
        );

        return $project;
    }

    private function removeTaskHistory(Project $project): void
    {
        $groupIds = $project->tasks()->pluck('id');
        $vm = TaskVm::query()->whereIn('group_id', $groupIds)->live()->first();

        if ($vm instanceof TaskVm) {
            throw new ResourceOperationException(
                errorCode: 'project.task_vm_not_destroyed',
                message: "Project [{$project->slug}] still has task VM [{$vm->name}] in state [{$vm->state->value}].",
                status: 409,
            );
        }

        $taskIds = DB::table('tasks')->whereIn('id', $groupIds)->orWhereIn('parent_id', $groupIds)->pluck('id');
        $leases = TaskWorkspaceLeaseRecord::query()->where(static function (Builder $query) use ($groupIds, $taskIds): void {
            $query->whereIn('group_id', $groupIds)->orWhereIn('task_id', $taskIds);
        });
        // A cancelled lease is a withdrawn waiter that never held the workspace.
        $lease = (clone $leases)->whereNotIn('status', ['released', 'cancelled'])->first();

        if ($lease instanceof TaskWorkspaceLeaseRecord) {
            throw new ResourceOperationException(
                errorCode: 'project.task_workspace_lease_active',
                message: "Project [{$project->slug}] still has workspace lease [{$lease->id}] of kind [{$lease->kind}] in status [{$lease->status}].",
                status: 409,
            );
        }

        $leases->whereIn('status', ['released', 'cancelled']);

        DB::table('task_workspace_operations')->whereIn('lease_id', (clone $leases)->select('id'))->delete();
        $leases->delete();
        DB::table('jev_decisions')->whereIn('task_group_id', $groupIds)->orWhereIn('task_id', $taskIds)->delete();
        DB::table('problem_fingerprints')->whereIn('task_group_id', $groupIds)->delete();
        DB::table('task_vms')->whereIn('group_id', $groupIds)->where('state', TaskVmState::Destroyed)->delete();
        $project->tasks()->delete();
    }
}
