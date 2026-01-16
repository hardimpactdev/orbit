<?php

namespace App\Services\OrbitCli;

use App\Http\Integrations\Orbit\Requests\GetWorktreesRequest;
use App\Http\Integrations\Orbit\Requests\RefreshWorktreesRequest;
use App\Http\Integrations\Orbit\Requests\UnlinkWorktreeRequest;
use App\Models\Environment;
use App\Services\OrbitCli\Shared\CommandService;
use App\Services\OrbitCli\Shared\ConnectorService;

/**
 * Service for git worktree management.
 */
class WorktreeService
{
    public function __construct(
        protected ConnectorService $connector,
        protected CommandService $command
    ) {}

    /**
     * Get all worktrees for a server (optionally filtered by site).
     */
    public function worktrees(Environment $environment, ?string $site = null): array
    {
        if ($environment->is_local) {
            $command = $site
                ? "worktrees {$site} --json"
                : 'worktrees --json';

            return $this->command->executeCommand($environment, $command);
        }

        return $this->connector->sendRequest($environment, new GetWorktreesRequest($site));
    }

    /**
     * Unlink a worktree from a site.
     */
    public function unlinkWorktree(Environment $environment, string $site, string $worktreeName): array
    {
        if ($environment->is_local) {
            $escapedSite = escapeshellarg($site);
            $escapedWorktree = escapeshellarg($worktreeName);

            return $this->command->executeCommand($environment, "worktree:unlink {$escapedSite} {$escapedWorktree} --json");
        }

        return $this->connector->sendRequest($environment, new UnlinkWorktreeRequest($site, $worktreeName));
    }

    /**
     * Refresh worktree detection (re-scan and auto-link new worktrees).
     */
    public function refreshWorktrees(Environment $environment): array
    {
        if ($environment->is_local) {
            return $this->command->executeCommand($environment, 'worktree:refresh --json');
        }

        return $this->connector->sendRequest($environment, new RefreshWorktreesRequest);
    }
}
