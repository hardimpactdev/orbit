<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Contracts\AppRuntimeUserResolver;
use App\Models\Node;
use App\Models\Project;
use App\Services\Nodes\Roles\NodeRoleAssignments;

final readonly class AppRuntimeUser implements AppRuntimeUserResolver
{
    public function forApp(Project $app): string
    {
        $app->loadMissing('node');

        if (! $this->isProduction($app)) {
            return $this->nodeUser($app);
        }

        return $this->productionUser($app);
    }

    public function containerUserForApp(Project $app): ?string
    {
        if (! $this->isProduction($app)) {
            return null;
        }

        return $this->productionUser($app);
    }

    private function isProduction(Project $app): bool
    {
        $app->loadMissing('node');

        if ($app->environment === 'production') {
            return true;
        }

        return $app->node instanceof Node && app(NodeRoleAssignments::class)->nodeHasActiveRole($app->node, 'app-prod');
    }

    private function productionUser(Project $app): string
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

    private function nodeUser(Project $app): string
    {
        return $app->node?->user ?: 'orbit';
    }
}
