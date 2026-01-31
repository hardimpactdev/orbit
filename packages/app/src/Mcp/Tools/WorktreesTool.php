<?php

declare(strict_types=1);

namespace HardImpact\Orbit\App\Mcp\Tools;

use HardImpact\Orbit\Core\Models\Environment;
use HardImpact\Orbit\Core\Services\OrbitCli\WorktreeService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class WorktreesTool extends Tool
{
    protected string $name = 'orbit_worktrees';

    protected string $description = 'List git worktrees with their subdomains';

    public function __construct(protected WorktreeService $worktreeService) {}

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'site' => $schema->string()->description('Filter to a specific project (optional - returns all if omitted)'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $environment = Environment::getLocal();

        if (! $environment) {
            return Response::error('No local environment configured');
        }

        $site = $request->get('site');

        $result = $this->worktreeService->worktrees($environment, $site);

        if (! $result['success']) {
            return Response::error($result['error'] ?? 'Failed to get worktrees');
        }

        $worktrees = $result['data']['worktrees'] ?? [];

        if ($site) {
            return Response::structured([
                'site' => $site,
                'worktrees' => $worktrees,
                'count' => count($worktrees),
            ]);
        }

        // Group by site for better organization
        $groupedWorktrees = [];
        foreach ($worktrees as $worktree) {
            $siteName = $worktree['site'] ?? 'unknown';
            if (! isset($groupedWorktrees[$siteName])) {
                $groupedWorktrees[$siteName] = [];
            }
            $groupedWorktrees[$siteName][] = $worktree;
        }

        return Response::structured([
            'worktrees' => $worktrees,
            'count' => count($worktrees),
            'sites_with_worktrees' => count($groupedWorktrees),
            'grouped_by_site' => $groupedWorktrees,
        ]);
    }
}
