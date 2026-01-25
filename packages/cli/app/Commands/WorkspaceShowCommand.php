<?php

namespace App\Commands;

use App\Concerns\WithJsonOutput;
use App\Services\WorkspaceService;
use LaravelZero\Framework\Commands\Command;

class WorkspaceShowCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'workspace:show 
        {name : Name of the workspace}
        {--json : Output as JSON}';

    protected $description = 'Show workspace details';

    public function handle(WorkspaceService $workspaceService): int
    {
        $name = $this->argument('name');
        $info = $workspaceService->getWorkspaceInfo($name);

        if (! is_dir($info['path'])) {
            if ($this->wantsJson()) {
                return $this->outputJsonError("Workspace '{$name}' does not exist");
            }

            $this->error("Workspace '{$name}' does not exist");

            return self::FAILURE;
        }

        if ($this->wantsJson()) {
            return $this->outputJsonSuccess($info);
        }

        $this->info("Workspace: {$info['name']}");
        $this->line("Path: {$info['path']}");
        $this->newLine();

        if (empty($info['projects'])) {
            $this->warn('No projects in this workspace.');
        } else {
            $this->info('Projects:');
            foreach ($info['projects'] as $project) {
                $this->line("  - {$project['name']} ({$project['path']})");
            }
        }

        return self::SUCCESS;
    }
}
