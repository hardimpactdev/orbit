<?php

declare(strict_types=1);

namespace App\Commands;

use App\Concerns\WithJsonOutput;
use App\Services\WorkspaceService;
use LaravelZero\Framework\Commands\Command;

class WorkspaceDeleteCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'workspace:delete 
        {name : Name of the workspace}
        {--force : Skip confirmation}
        {--json : Output as JSON}';

    protected $description = 'Delete a workspace (symlinks only, not the actual projects)';

    public function handle(WorkspaceService $workspaceService): int
    {
        $name = $this->argument('name');

        if (! $this->option('force') && ! $this->option('json')) {
            if (! $this->confirm("Are you sure you want to delete workspace '{$name}'? This will remove the workspace directory and all symlinks (but not the actual projects).")) {
                $this->info('Cancelled.');

                return self::SUCCESS;
            }
        }

        try {
            $workspaceService->delete($name);

            if ($this->wantsJson()) {
                return $this->outputJsonSuccess([
                    'message' => "Workspace '{$name}' deleted successfully",
                ]);
            }

            $this->info("Workspace '{$name}' deleted successfully");

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
