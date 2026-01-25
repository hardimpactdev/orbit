<?php

namespace App\Commands;

use App\Concerns\WithJsonOutput;
use App\Services\WorkspaceService;
use LaravelZero\Framework\Commands\Command;

class WorkspaceAddCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'workspace:add 
        {workspace : Name of the workspace}
        {project : Name of the project to add}
        {--json : Output as JSON}';

    protected $description = 'Add a project to a workspace';

    public function handle(WorkspaceService $workspaceService): int
    {
        $workspace = $this->argument('workspace');
        $project = $this->argument('project');

        try {
            $info = $workspaceService->addProject($workspace, $project);

            if ($this->wantsJson()) {
                return $this->outputJson([
                    'success' => true,
                    'message' => "Project '{$project}' added to workspace '{$workspace}'",
                    'workspace' => $info,
                ]);
            }

            $this->info("Project '{$project}' added to workspace '{$workspace}'");
            $this->line('Projects in workspace: '.$info['project_count']);

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
