<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Ui\Mcp\Tools;

use HardImpact\Orbit\Core\Models\Environment;
use HardImpact\Orbit\Core\Services\OrbitCli\ConfigurationService;
use HardImpact\Orbit\Core\Services\OrbitCli\StatusService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
final class ProjectsTool extends Tool
{
    protected string $name = 'orbit_projects';

    protected string $description = 'List all registered projects with their domains, paths, PHP versions, and configuration details';

    public function __construct(
        protected StatusService $statusService,
        protected ConfigurationService $configService
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): ResponseFactory
    {
        $environment = Environment::getLocal();

        if (! $environment) {
            return Response::structured([
                'success' => false,
                'error' => 'No local environment configured',
            ]);
        }

        $projectsResult = $this->statusService->projects($environment);
        $configResult = $this->configService->getConfig($environment);

        if (! $projectsResult['success']) {
            return Response::structured([
                'success' => false,
                'error' => $projectsResult['error'] ?? 'Failed to get projects',
            ]);
        }

        $projects = $projectsResult['data']['projects'] ?? [];
        $config = $configResult['success'] ? ($configResult['data'] ?? []) : [];
        $defaultPhp = $config['default_php_version'] ?? '8.4';

        $formattedProjects = array_map(fn ($project) => [
            'name' => $project['name'],
            'domain' => $project['domain'] ?? null,
            'path' => $project['path'],
            'php_version' => $project['php_version'] ?? $defaultPhp,
            'has_custom_php' => ($project['php_version'] ?? $defaultPhp) !== $defaultPhp,
            'secure' => $project['secure'] ?? true,
        ], $projects);

        return Response::structured([
            'projects' => $formattedProjects,
            'default_php_version' => $defaultPhp,
            'projects_count' => count($formattedProjects),
        ]);
    }
}
