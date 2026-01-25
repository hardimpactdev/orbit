<?php

namespace App\Commands;

use App\Concerns\WithJsonOutput;
use App\Services\WorktreeService;
use LaravelZero\Framework\Commands\Command;

class WorktreeRefreshCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'worktree:refresh 
                            {--cleanup : Also remove orphaned worktree entries}
                            {--json : Output as JSON}';

    protected $description = 'Refresh worktree detection and auto-link new worktrees';

    public function handle(WorktreeService $worktreeService): int
    {
        try {
            $removed = 0;

            if ($this->option('cleanup')) {
                $removed = $worktreeService->cleanupOrphaned();
            }

            $worktrees = $worktreeService->refresh();
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
                'orphaned_removed' => $removed,
            ]);
        }

        $this->info('Worktree scan complete.');
        $this->line('Found '.count($worktrees).' worktree(s).');

        if ($removed > 0) {
            $this->line("Removed {$removed} orphaned worktree entry/entries.");
        }

        if (count($worktrees) > 0) {
            $this->newLine();
            $this->info('Active worktrees:');
            foreach ($worktrees as $wt) {
                $this->line("  - {$wt['domain']} ({$wt['site']}/{$wt['name']})");
            }
        }

        return self::SUCCESS;
    }
}
