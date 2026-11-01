<?php

declare(strict_types=1);

namespace App\Domain\Projects;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Support\Facades\DB;

/** Captures Gateway state. Native workflows own scanning and schedule selection. */
final readonly class ProjectMaintenanceIntent
{
    public function __construct(private StartOperationAction $start, private OperationIdempotency $idempotency) {}

    public function start(Project $project, string $mode, ?string $requestId = null): Operation
    {
        if (! in_array($mode, ['detect', 'routine', 'observe'], true)) {
            throw new ResourceOperationException('project.maintenance_invalid', 'Unknown Project maintenance request.', 422);
        }
        $kind = 'project.'.$mode;
        if (($existing = $this->idempotency->replay($kind)) !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($project, $kind, $requestId): Operation {
            $project = Project::query()->lockForUpdate()->findOrFail($project->id);
            if ($project->source_access !== ProjectSourceAccess::GitHubApp || $project->repository_url === '' || ! is_string($project->task_check) || $project->task_check === '' || ! is_string($project->default_branch) || $project->default_branch === '') {
                throw new ResourceOperationException('project.maintenance_unavailable', 'Project maintenance requires its GitHub App repository and configured check.', 422);
            }
            $groups = Task::topLevel()->where('project_id', $project->id)->orderBy('id')->get([
                'id', 'project_id', 'status', 'orchestration', 'brief', 'pr_url', 'watched_pr_url', 'watched_pr_state', 'taskable_id', 'maintenance',
            ])->toArray();

            return $this->start->execute($kind, 'project', $project->id, 209, [
                'project' => ['id' => $project->id, 'repository' => $project->repository_url,
                    'default_branch' => $project->default_branch, 'check' => $project->task_check],
                'groups' => $groups, 'admitted_at' => now()->toIso8601String(),
            ], 'project-maintenance', $requestId);
        });
    }
}
