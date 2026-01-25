<?php

namespace App\Commands;

use App\Concerns\WithJsonOutput;
use App\Services\WorkspaceService;
use LaravelZero\Framework\Commands\Command;

class WorkspaceCreateCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'workspace:create 
        {name : Name of the workspace}
        {--json : Output as JSON}';

    protected $description = 'Create a new workspace';

    public function handle(WorkspaceService $workspaceService): int
    {
        $name = $this->argument('name');

        try {
            $workspace = $workspaceService->create($name);

            if ($this->wantsJson()) {
                return $this->outputJsonSuccess([
                    'message' => "Workspace '{$name}' created successfully",
                    'workspace' => $workspace,
                ]);
            }

            $this->info("Workspace '{$name}' created at: {$workspace['path']}");
            $this->line("Add projects with: orbit workspace:add {$name} <project>");

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
