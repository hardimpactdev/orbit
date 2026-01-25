<?php

namespace App\Commands;

use App\Concerns\WithJsonOutput;
use App\Services\WorkspaceService;
use LaravelZero\Framework\Commands\Command;

class WorkspacesCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'workspaces {--json : Output as JSON}';

    protected $description = 'List all workspaces';

    public function handle(WorkspaceService $workspaceService): int
    {
        $workspaces = $workspaceService->list();

        if ($this->wantsJson()) {
            return $this->outputJsonSuccess([
                'workspaces' => $workspaces,
                'workspaces_count' => count($workspaces),
            ]);
        }

        if (empty($workspaces)) {
            $this->warn('No workspaces found. Create one with: orbit workspace:create <name>');

            return self::SUCCESS;
        }

        $this->info('Workspaces:');
        $this->newLine();

        $tableData = [];
        foreach ($workspaces as $workspace) {
            $tableData[] = [
                $workspace['name'],
                $workspace['project_count'],
                $workspace['path'],
            ];
        }

        $this->table(['Name', 'Projects', 'Path'], $tableData);

        return self::SUCCESS;
    }
}
