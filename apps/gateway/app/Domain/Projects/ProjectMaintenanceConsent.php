<?php

declare(strict_types=1);

namespace App\Domain\Projects;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

/** Source policy does not grant consent to maintain a Project. */
final readonly class ProjectMaintenanceConsent
{
    public function change(Project $project, bool $enabled): Project
    {
        return DB::transaction(function () use ($project, $enabled): Project {
            $current = Project::query()->lockForUpdate()->findOrFail($project->id);
            if ($enabled) {
                $this->assertAvailable($current);
            }
            $current->update(['maintenance_enabled' => $enabled]);

            return $current;
        });
    }

    public function assertEnabled(Project $project): void
    {
        if (! $project->maintenance_enabled) {
            throw new ResourceOperationException('project.maintenance_disabled', 'Enable maintenance for this Project first.', 422);
        }
        $this->assertAvailable($project);
    }

    private function assertAvailable(Project $project): void
    {
        if ($project->source_access !== ProjectSourceAccess::GitHubApp || $project->repository_url === ''
            || ! is_string($project->task_check) || $project->task_check === ''
            || ! is_string($project->default_branch) || $project->default_branch === '') {
            throw new ResourceOperationException('project.maintenance_unavailable', 'Project maintenance requires its GitHub App repository and configured check.', 422);
        }
    }
}
