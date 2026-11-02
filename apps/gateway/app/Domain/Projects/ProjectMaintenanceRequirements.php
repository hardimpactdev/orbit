<?php

declare(strict_types=1);

namespace App\Domain\Projects;

use App\Models\Project;

/** Dependency maintenance reads the default branch through the GitHub App and verifies updates with the task check. */
final readonly class ProjectMaintenanceRequirements
{
    /** @return array<string, string> field => message for each requirement the Project misses */
    public static function missing(Project $project): array
    {
        $missing = [];
        if ($project->source_access !== ProjectSourceAccess::GitHubApp || $project->repository_url === '') {
            $missing['source_access'] = 'Dependency maintenance needs source access through the GitHub App.';
        }
        if (! is_string($project->default_branch) || $project->default_branch === '') {
            $missing['default_branch'] = 'Dependency maintenance needs a default branch.';
        }
        if (! is_string($project->task_check) || $project->task_check === '') {
            $missing['task_check'] = 'Dependency maintenance needs a task check.';
        }

        return $missing;
    }
}
