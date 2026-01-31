<?php

declare(strict_types=1);

namespace HardImpact\Orbit\App\Mcp\Resources;

use HardImpact\Orbit\Core\Models\Environment;
use HardImpact\Orbit\Core\Services\OrbitCli\ConfigurationService;
use HardImpact\Orbit\Core\Services\OrbitCli\StatusService;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Resource;

class ProjectsResource extends Resource
{
    protected string $uri = 'orbit://projects';

    protected string $mimeType = 'application/json';

    public function __construct(
        protected StatusService $statusService,
        protected ConfigurationService $configService
    ) {}

    public function name(): string
    {
        return 'projects';
    }

    public function title(): string
    {
        return 'Projects';
    }

    public function description(): string
    {
        return 'All registered projects with their domains, paths, PHP versions, and custom settings.';
    }

    public function handle(Request $request): Response
    {
        $environment = Environment::getLocal();

        if (! $environment) {
            return Response::json([
                'error' => 'No local environment configured',
            ]);
        }

        // Get projects from CLI
        $projectsResult = $this->statusService->projects($environment);
        $configResult = $this->configService->getConfig($environment);

        if (! $projectsResult['success']) {
            return Response::json([
                'error' => $projectsResult['error'] ?? 'Failed to get projects',
            ]);
        }

        $projects = $projectsResult['data']['projects'] ?? [];
        $config = $configResult['success'] ? ($configResult['data'] ?? []) : [];
        $defaultPhp = $config['default_php_version'] ?? '8.4';
        $tld = $config['tld'] ?? 'test';

        $formattedProjects = array_values(array_map(fn ($project) => [
            'name' => $project['name'],
            'display_name' => $project['display_name'] ?? ucwords(str_replace(['-', '_'], ' ', $project['name'])),
            'github_repo' => $project['github_repo'] ?? null,
            'project_type' => $project['project_type'] ?? 'unknown',
            'domain' => $project['domain'] ?? null,
            'path' => $project['path'],
            'php_version' => $project['php_version'] ?? $defaultPhp,
            'has_custom_php' => ($project['php_version'] ?? $defaultPhp) !== $defaultPhp,
            'secure' => true,
        ], $projects));

        return Response::json([
            'projects' => $formattedProjects,
            'summary' => [
                'total' => count($projects),
                'with_custom_php' => count(array_filter($formattedProjects, fn ($p) => $p['has_custom_php'])),
                'default_php_version' => $defaultPhp,
                'tld' => $tld,
            ],
        ]);
    }
}
