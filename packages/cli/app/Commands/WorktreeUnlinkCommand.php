<?php

declare(strict_types=1);

namespace App\Commands;

use App\Concerns\WithJsonOutput;
use App\Services\WorktreeService;
use LaravelZero\Framework\Commands\Command;

final class WorktreeUnlinkCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'worktree:unlink 
                            {site : The site name}
                            {worktree : The worktree name to unlink}
                            {--json : Output as JSON}';

    protected $description = 'Unlink a worktree from a site (removes Caddy routing)';

    public function handle(WorktreeService $worktreeService): int
    {
        $siteName = $this->argument('site');
        $worktreeName = $this->argument('worktree');

        try {
            $success = $worktreeService->unlinkWorktree($siteName, $worktreeName);
        } catch (\Exception $e) {
            if ($this->wantsJson()) {
                return $this->outputJsonError($e->getMessage());
            }
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if (! $success) {
            $message = "Worktree '{$worktreeName}' not found for site '{$siteName}'.";
            if ($this->wantsJson()) {
                return $this->outputJsonError($message);
            }
            $this->error($message);

            return self::FAILURE;
        }

        if ($this->wantsJson()) {
            return $this->outputJsonSuccess([
                'site' => $siteName,
                'worktree' => $worktreeName,
                'unlinked' => true,
            ]);
        }

        $this->info("Worktree '{$worktreeName}' unlinked from site '{$siteName}'.");
        $this->line('Caddy configuration has been updated.');

        return self::SUCCESS;
    }
}
