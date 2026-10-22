<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Projects\ProjectRemovalHook;
use App\Domain\Routes\RouteRemovalGuard;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskGroupStatus;
use App\Models\Project;
use Illuminate\Container\Attributes\Tag;
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
        $taskIds = DB::table('tasks')->whereIn('id', $groupIds)->orWhereIn('parent_id', $groupIds)->pluck('id');
        $leases = DB::table('task_workspace_leases')->whereIn('group_id', $groupIds)->orWhereIn('task_id', $taskIds);

        DB::table('task_workspace_operations')->whereIn('lease_id', (clone $leases)->select('id'))->delete();
        $leases->delete();
        DB::table('jev_decisions')->whereIn('task_group_id', $groupIds)->orWhereIn('task_id', $taskIds)->delete();
        DB::table('problem_fingerprints')->whereIn('task_group_id', $groupIds)->delete();
        DB::table('task_vms')->whereIn('group_id', $groupIds)->delete();
        $project->tasks()->delete();
    }
}
