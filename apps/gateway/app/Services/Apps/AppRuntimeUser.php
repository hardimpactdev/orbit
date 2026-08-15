<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Contracts\AppRuntimeUserResolver;
use App\Models\App;
use App\Models\Node;
use App\Services\Nodes\Roles\NodeRoleAssignments;

final readonly class AppRuntimeUser implements AppRuntimeUserResolver
{
    public function forApp(App $app): string
    {
        $app->loadMissing('node');

        if (! $this->isProduction($app)) {
            return $this->nodeUser($app);
        }

        return $this->productionUser($app);
    }

    public function containerUserForApp(App $app): ?string
    {
        if (! $this->isProduction($app)) {
            return null;
        }

        return $this->productionUser($app);
    }

    /**
     * Home directory the runtime user owns, matching the layout
     * `internal:app-security:repair` creates via `useradd --home-dir`.
     */
    public function homeForApp(App $app): string
    {
        return $this->homeFor($this->forApp($app));
    }

    public function homeFor(string $user): string
    {
        return $user === 'root' ? '/root' : "/home/{$user}";
    }

    private function isProduction(App $app): bool
    {
        $app->loadMissing('node');

        if ($app->environment === 'production') {
            return true;
        }

        return $app->node instanceof Node && app(NodeRoleAssignments::class)->nodeHasActiveRole($app->node, 'app-prod');
    }

    private function productionUser(App $app): string
    {
        return $this->userFromHomePath($app->path) ?? $this->nodeUser($app);
    }

    private function userFromHomePath(string $path): ?string
    {
        if (preg_match('#^/home/([^/]+)/#', $path, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    private function nodeUser(App $app): string
    {
        return $app->node?->user ?: 'orbit';
    }
}
