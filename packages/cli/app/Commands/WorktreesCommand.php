<?php

namespace App\Commands;

use App\Concerns\WithJsonOutput;
use App\Services\WorktreeService;
use LaravelZero\Framework\Commands\Command;

class WorktreesCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'worktrees 
                            {site? : Filter worktrees by site name}
                            {--json : Output as JSON}';

    protected $description = 'List all git worktrees with their subdomains';

    public function handle(WorktreeService $worktreeService): int
    {
        $siteName = $this->argument('site');

        try {
            if ($siteName) {
                $worktrees = $worktreeService->getSiteWorktrees($siteName);
            } else {
                $worktrees = $worktreeService->getAllWorktrees();
            }
        } catch (\Exception $e) {
            if ($this->wantsJson()) {
                return $this->outputJsonError($e->getMessage());
            }
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->wantsJson()) {
            return $this->outputJsonSuccess([
                'worktrees' => $worktrees,
                'worktrees_count' => count($worktrees),
            ]);
        }

        if (empty($worktrees)) {
            $this->info('No worktrees found.');
            if ($siteName) {
                $this->line("Site: {$siteName}");
            }

            return self::SUCCESS;
        }

        $this->info('Worktrees:');
        $this->newLine();

        $tableData = [];
        foreach ($worktrees as $worktree) {
            $tableData[] = [
                $worktree['site'],
                $worktree['name'],
                $worktree['domain'],
                $worktree['branch'] ?? '-',
                $this->truncatePath($worktree['path'], 40),
            ];
        }

        $this->table(
            ['Site', 'Worktree', 'Domain', 'Branch', 'Path'],
            $tableData
        );

        $this->newLine();
        $this->line('Total worktrees: '.count($worktrees));

        return self::SUCCESS;
    }

    protected function truncatePath(string $path, int $maxLength): string
    {
        if (strlen($path) <= $maxLength) {
            return $path;
        }

        return '...'.substr($path, -(int) ($maxLength - 3));
    }
}
