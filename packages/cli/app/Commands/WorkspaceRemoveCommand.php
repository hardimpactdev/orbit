<?php

namespace App\Commands;

use App\Concerns\WithJsonOutput;
use App\Services\WorkspaceService;
use LaravelZero\Framework\Commands\Command;

class WorkspaceRemoveCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'workspace:remove 
        {workspace : Name of the workspace}
        {project : Name of the project to remove}
        {--json : Output as JSON}';

    protected $description = 'Remove a project from a workspace';

    public function handle(WorkspaceService $workspaceService): int
    {
        $workspace = $this->argument('workspace');
        $project = $this->argument('project');

        try {
            $info = $workspaceService->removeProject($workspace, $project);

            if ($this->wantsJson()) {
                return $this->outputJson([
                    'success' => true,
                    'message' => "Project '{$project}' removed from workspace '{$workspace}'",
                    'workspace' => $info,
                ]);
            }

            $this->info("Project '{$project}' removed from workspace '{$workspace}'");
            $this->line('Projects remaining in workspace: '.$info['project_count']);

            return self::SUCCESS;
        } catch (\RuntimeException $e) {
            if ($this->wantsJson()) {
                return $this->outputJsonError($e->getMessage());
            }

            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
